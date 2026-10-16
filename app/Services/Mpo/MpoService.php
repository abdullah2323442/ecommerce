<?php

namespace App\Services\Mpo;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\Medicine;
use App\Models\MpoListing;
use App\Models\Preorder;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Stock\StockException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The MPO sector (spec ch 10). An MPO is a company representative whose account only the owner opens. They choose, from the
 * companies they are given, the products they work with, and submit those. The owner approves each one, sets the markets and the
 * price in each, and buyers only ever see "Anonymous N (store name)". Both sides stay masked: the MPO never sees a buyer and a
 * buyer never sees an MPO. Only the owner sees everyone. The goods pass through the owner's hands and enter stock; money with the
 * MPO is settled outside the platform, so there is only an order record.
 */
class MpoService
{
    public function __construct(private ActivityLogger $activity) {}

    // ------------------------------------------------------------------ the owner opens and manages accounts

    /**
     * @param  array{name: string, phone: ?string, email: ?string, password: string, area: ?string, note: ?string, company_ids: list<int>}  $data
     */
    public function open(array $data, User $by): User
    {
        return DB::transaction(function () use ($data, $by) {
            $number = (int) User::where('account_type', AccountType::Mpo->value)->max('mpo_number') + 1;

            $mpo = new User;
            $mpo->forceFill([
                'name' => $data['name'], 'phone' => $data['phone'] ?? null ?: null,
                'email' => ($data['email'] ?? null) ?: 'mpo'.$number.'-'.Str::lower(Str::random(6)).'@phone.invalid',
                'password' => Hash::make($data['password']), 'account_type' => AccountType::Mpo, 'area' => $data['area'] ?? null,
                'mpo_number' => $number, 'mpo_note' => $data['note'] ?? null, 'email_verified_at' => now(),
            ])->save();

            $mpo->mpoCompanies()->sync($data['company_ids'] ?? []);
            $this->activity->record('mpo.opened', $mpo, ['area' => $data['area'] ?? null, 'companies' => $data['company_ids'] ?? []], $by);

            return $mpo;
        });
    }

    /** Give or take away a company. Taking one away also takes away the products chosen from it, and withdraws what is listed. */
    public function setCompanies(User $mpo, array $companyIds, User $by): void
    {
        $this->mustBeMpo($mpo);
        DB::transaction(function () use ($mpo, $companyIds, $by) {
            $mpo->mpoCompanies()->sync($companyIds);
            $stray = $mpo->mpoProducts()->whereNotIn('medicines.company_id', $companyIds ?: [0])->pluck('medicines.id');
            $mpo->mpoProducts()->detach($stray->all());
            MpoListing::where('user_id', $mpo->id)->whereIn('medicine_id', $stray)->whereIn('status', ['submitted', 'approved'])->update(['status' => 'closed']);
            $this->activity->record('mpo.companies', $mpo, ['companies' => $companyIds], $by);
        });
    }

    /** Close the account: the products come off the market. Orders already made must still be completed. */
    public function close(User $mpo, User $by): void
    {
        $this->mustBeMpo($mpo);
        DB::transaction(function () use ($mpo, $by) {
            MpoListing::where('user_id', $mpo->id)->whereIn('status', ['submitted', 'approved'])->update(['status' => 'closed']);
            $mpo->forceFill(['suspended_at' => now(), 'suspend_reason' => 'Account closed'])->save();
            $this->activity->record('mpo.closed', $mpo, [], $by);
        });
    }

    // ------------------------------------------------------------------ what the MPO does

    /** Choose the products they work with, only from their own companies. Replaces the old choice. */
    public function chooseProducts(User $mpo, array $medicineIds): void
    {
        $this->mustBeMpo($mpo);
        $allowed = Medicine::whereIn('company_id', $mpo->mpoCompanies()->pluck('companies.id'))->whereIn('id', $medicineIds)->pluck('id');
        $mpo->mpoProducts()->sync($allowed->all());
        // A product they no longer work with comes off the market.
        MpoListing::where('user_id', $mpo->id)->whereNotIn('medicine_id', $allowed->all() ?: [0])->whereIn('status', ['submitted', 'approved'])->update(['status' => 'closed']);
    }

    /**
     * Submit one product. Only chosen products can be submitted. It goes to the owner first; it is not on the market yet.
     * Submitting again changes the open listing instead of making a second one.
     *
     * @param  array{stock_status: string, arrive_days: ?int, quantity: ?int, bonus_buy: ?int, bonus_free: ?int}  $data
     *
     * @throws StockException
     */
    public function submit(User $mpo, int $medicineId, array $data): MpoListing
    {
        $this->mustBeMpo($mpo);

        if ($mpo->isSuspended()) {
            throw new StockException('This account is closed.');
        }

        if (! $mpo->mpoProducts()->where('medicines.id', $medicineId)->exists()) {
            throw new StockException('Choose this product among the ones you work with first.');
        }

        if ($data['stock_status'] === 'pre_order' && ! in_array((int) ($data['arrive_days'] ?? 0), [2, 3, 4, 5], true)) {
            throw new StockException('Say in how many days it arrives: 2, 3, 4 or 5.');
        }

        $bonus = ((int) ($data['bonus_buy'] ?? 0)) > 0 && ((int) ($data['bonus_free'] ?? 0)) > 0;
        $fields = [
            'stock_status' => $data['stock_status'], 'arrive_days' => $data['stock_status'] === 'pre_order' ? (int) ($data['arrive_days'] ?? 0) : null,
            'quantity' => $data['quantity'] ?? null, 'bonus_buy' => $bonus ? (int) $data['bonus_buy'] : null, 'bonus_free' => $bonus ? (int) $data['bonus_free'] : null,
        ];

        $open = MpoListing::where('user_id', $mpo->id)->where('medicine_id', $medicineId)->whereIn('status', ['submitted', 'approved'])->first();

        // A change to an approved product goes back to the owner: prices and what is shown are his to decide.
        if ($open) {
            $open->update($fields + ['status' => 'submitted']);

            return $open->fresh();
        }

        return MpoListing::create($fields + ['user_id' => $mpo->id, 'medicine_id' => $medicineId, 'status' => 'submitted']);
    }

    public function withdraw(MpoListing $listing): void
    {
        $listing->update(['status' => 'closed']);
    }

    // ------------------------------------------------------------------ the owner reviews

    /**
     * @param  array{markets: list<string>, price_wholesale: ?float, price_paikari: ?float, price_public: ?float, show_bonus: bool, show_quantity: bool, stock_status: string, arrive_days: ?int}  $data
     *
     * @throws StockException
     */
    public function approve(MpoListing $listing, array $data, User $by): MpoListing
    {
        if ($listing->status !== 'submitted') {
            throw new StockException('This product is not waiting for a decision.');
        }

        $markets = array_values(array_intersect(MpoListing::MARKETS, (array) $data['markets']));
        if ($markets === []) {
            throw new StockException('Pick at least one market.');
        }

        foreach ($markets as $m) {
            if (($data['price_'.$m] ?? null) === null || (float) $data['price_'.$m] <= 0) {
                throw new StockException('Set a price for every market it shows in.');
            }
        }

        if ($data['stock_status'] === 'pre_order' && ! in_array((int) ($data['arrive_days'] ?? 0), [2, 3, 4, 5], true)) {
            throw new StockException('Say in how many days it arrives: 2, 3, 4 or 5.');
        }

        $listing->update([
            'status' => 'approved', 'markets' => $markets,
            'price_wholesale' => in_array('wholesale', $markets, true) ? $data['price_wholesale'] : null,
            'price_paikari' => in_array('paikari', $markets, true) ? $data['price_paikari'] : null,
            'price_public' => in_array('public', $markets, true) ? $data['price_public'] : null,
            'show_bonus' => (bool) ($data['show_bonus'] ?? false) && $listing->bonus_buy !== null, 'show_quantity' => (bool) ($data['show_quantity'] ?? false),
            'stock_status' => $data['stock_status'], 'arrive_days' => $data['stock_status'] === 'pre_order' ? (int) $data['arrive_days'] : null,
            'approved_by' => $by->id, 'approved_at' => now(), 'review_note' => null,
        ]);
        $this->activity->record('mpo.listing_approved', $listing, ['markets' => $markets], $by);

        return $listing->fresh();
    }

    public function reject(MpoListing $listing, string $note, User $by): MpoListing
    {
        if ($listing->status !== 'submitted') {
            throw new StockException('This product is not waiting for a decision.');
        }

        $listing->update(['status' => 'rejected', 'review_note' => $note]);
        $this->activity->record('mpo.listing_rejected', $listing, ['note' => $note], $by);

        return $listing->fresh();
    }

    // ------------------------------------------------------------------ what buyers see

    /**
     * Approved listings of one medicine for one market, as buyers see them: an alias, a price, the bonus and the stock words.
     * Nothing here says who the MPO is, and the MPO's own id never leaves the server.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function offersFor(Medicine $medicine, string $market, ?User $viewer = null): Collection
    {
        $mine = $viewer && $market === 'wholesale' ? app(MpoBidding::class)->mine($viewer) : collect();

        return MpoListing::with('mpo:id,mpo_number')->where('medicine_id', $medicine->id)->where('status', 'approved')->get()
            ->filter(fn (MpoListing $l) => $l->priceIn($market) !== null && ! $l->mpo?->isSuspended())
            ->map(fn (MpoListing $l) => $this->card($l, $market) + ($market === 'wholesale' ? ['can_bid' => true, 'my_bid' => $mine->has($l->id) ? ['id' => $mine[$l->id]->id, 'percent' => (float) $mine[$l->id]->percent, 'boxes' => (int) $mine[$l->id]->boxes] : null] : []))->values();
    }

    /** @return array<string, mixed> */
    public function card(MpoListing $l, string $market): array
    {
        return [
            'id' => $l->id, 'seller' => $l->alias(), 'price' => $l->priceIn($market),
            'bonus' => $l->show_bonus && $l->bonus_buy ? ['buy' => $l->bonus_buy, 'free' => $l->bonus_free] : null,
            'stock' => $l->stock_status === 'pre_order' ? 'pre_order' : 'in_stock', 'days' => $l->arrive_days,
            'quantity' => $l->show_quantity && $l->stock_status === 'in_stock' ? $l->quantity : null,
        ];
    }

    /**
     * A buyer orders from a listing at the price of their market. It becomes a pre-order that is already accepted: the owner
     * took the product and set the price when approving. When the goods reach the owner it becomes the real order.
     *
     * @throws StockException
     */
    public function order(User $buyer, MpoListing $listing, int $boxes): Preorder
    {
        $market = $this->marketOf($buyer);
        $price = $market ? $listing->priceIn($market) : null;

        if ($listing->status !== 'approved' || $price === null || $listing->mpo?->isSuspended()) {
            throw new StockException('This offer is no longer open.');
        }

        if ($boxes < 1 || $boxes > 100000) {
            throw new StockException('The quantity must be more than zero.');
        }

        $unit = $listing->medicine->packaging_type === 'box' ? 'box' : 'piece';
        $free = $listing->show_bonus && $listing->bonus_buy ? intdiv($boxes, $listing->bonus_buy) * (int) $listing->bonus_free : 0;
        $days = $listing->stock_status === 'pre_order' ? (int) $listing->arrive_days : 3;

        return DB::transaction(function () use ($buyer, $listing, $boxes, $unit, $price, $free, $days) {
            $pre = Preorder::create([
                'customer_id' => $buyer->id, 'status' => 'accepted', 'promised_days' => $days, 'due_on' => today()->addDays($days), 'accepted_at' => now(),
                'mpo_listing_id' => $listing->id, 'box_price' => $price, 'bonus_free' => $free ?: null,
            ]);
            $pre->lines()->create(['medicine_id' => $listing->medicine_id, 'unit' => $unit, 'quantity' => $boxes]);

            return $pre;
        });
    }

    public function marketOf(User $user): ?string
    {
        return match ($user->account_type) {
            AccountType::Wholesaler => 'wholesale',
            AccountType::Paikari => 'paikari',
            AccountType::Public => 'public',
            default => null,
        };
    }

    // ------------------------------------------------------------------ what the MPO sees: only their own, and no buyer

    /** The orders made from this MPO's listings: the proof that "my account" placed them. No buyer, no market, no price per buyer. */
    public function orderRecords(User $mpo): Collection
    {
        return Preorder::whereHas('listing', fn ($q) => $q->where('user_id', $mpo->id))->with('listing:id,medicine_id', 'listing.medicine:id,brand_name,strength', 'lines')->latest('id')->limit(50)->get()
            ->map(fn (Preorder $p) => [
                'id' => $p->id, 'medicine' => trim($p->listing->medicine->brand_name.' '.$p->listing->medicine->strength), 'quantity' => (float) ($p->lines->first()?->quantity ?? 0),
                'bonus' => (int) $p->bonus_free, 'status' => $p->status, 'when' => $p->created_at?->toIso8601String(), 'due_on' => $p->due_on?->toDateString(),
            ]);
    }

    private function mustBeMpo(User $user): void
    {
        if ($user->account_type !== AccountType::Mpo) {
            throw new StockException('This is not an MPO account.');
        }
    }
}
