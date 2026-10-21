<?php

namespace App\Services\Gaming;

use App\Models\Game;
use App\Models\GameOrder;
use App\Models\GamePackage;
use App\Models\Notice;
use App\Models\Setting;
use App\Models\User;
use App\Services\Messages\Messenger;
use App\Services\Stock\StockException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Game top-up (spec ch 18), like Codashop: pick a game, give the player ID, see the player's name (when a provider can say it), pick a package, pay
 * online, get the diamonds. There is no cash on delivery. Until a payment gateway and a top-up provider are connected, the buyer sends the money to
 * our bKash, Nagad or Rocket number and gives the transaction id; we check it and top up by hand. A failed top-up is refunded and listed apart.
 */
class GameTopUp
{
    public const METHODS = ['bkash', 'nagad', 'rocket'];

    public function __construct(private PlayerVerifier $verifier, private TopUpProvider $provider, private Messenger $messages) {}

    public function lateMinutes(): int
    {
        return max(1, (int) Setting::read('gaming.late_minutes', 15));
    }

    /** Where to send the money: "bkash: 01700000000, nagad: 01800000000" (a setting). @return array<string, string> */
    public function payNumbers(): array
    {
        return collect(explode(',', (string) Setting::read('gaming.pay_numbers', '')))
            ->mapWithKeys(function ($pair) {
                [$k, $v] = array_pad(array_map('trim', explode(':', $pair, 2)), 2, '');

                return in_array(mb_strtolower($k), self::METHODS, true) && $v !== '' ? [mb_strtolower($k) => $v] : [];
            })->all();
    }

    // ------------------------------------------------------------------ the catalogue (ours)

    public function saveGame(array $data, ?Game $game = null): Game
    {
        $name = trim((string) ($data['name'] ?? $game?->name ?? ''));

        if (mb_strlen($name) < 2) {
            throw new StockException('Give the game a name.');
        }

        $fields = [
            'name' => mb_substr($name, 0, 80), 'id_label' => mb_substr(trim((string) ($data['id_label'] ?? $game?->id_label ?? 'Player ID')) ?: 'Player ID', 0, 40),
            'needs_zone' => (bool) ($data['needs_zone'] ?? $game?->needs_zone ?? false), 'zone_label' => isset($data['zone_label']) ? mb_substr(trim((string) $data['zone_label']), 0, 40) ?: null : $game?->zone_label,
            'is_active' => (bool) ($data['is_active'] ?? $game?->is_active ?? true),
        ];

        if ($game) {
            $game->update($fields);

            return $game->fresh();
        }

        $slug = Str::slug($name) ?: 'game';
        $n = 1;
        while (Game::where('slug', $slug)->exists()) {
            $slug = Str::slug($name).'-'.(++$n);
        }

        return Game::create($fields + ['slug' => $slug, 'sort' => (int) Game::max('sort') + 1]);
    }

    public function savePackage(Game $game, array $data, ?GamePackage $package = null): GamePackage
    {
        $name = trim((string) ($data['name'] ?? $package?->name ?? ''));
        $price = $data['price'] ?? $package?->price;

        if (mb_strlen($name) < 2) {
            throw new StockException('Give the package a name, like 100 Diamonds.');
        }

        if (! is_numeric($price) || (float) $price <= 0 || (float) $price > 1000000) {
            throw new StockException('A price must be more than zero.');
        }

        $fields = ['name' => mb_substr($name, 0, 80), 'price' => round((float) $price, 2), 'is_active' => (bool) ($data['is_active'] ?? $package?->is_active ?? true)];

        if ($package) {
            abort_unless($package->game_id === $game->id, 404);
            $package->update($fields);

            return $package->fresh();
        }

        return $game->packages()->create($fields + ['sort' => (int) $game->packages()->max('sort') + 1]);
    }

    // ------------------------------------------------------------------ the buyer

    /** The name behind an ID, when a provider can say it. 'invalid' stops the order; 'unavailable' leaves the buyer to check. */
    public function verify(Game $game, string $playerId, ?string $zone): array
    {
        $playerId = trim($playerId);

        if (! preg_match('/^[A-Za-z0-9_\-]{4,40}$/', $playerId)) {
            return ['status' => 'invalid'];
        }

        if ($game->needs_zone && trim((string) $zone) === '') {
            return ['status' => 'invalid'];
        }

        return $this->verifier->lookup($game, $playerId, $zone !== null ? trim($zone) : null);
    }

    /** @throws StockException */
    public function place(?User $buyer, GamePackage $package, string $playerId, ?string $zone, string $phone, bool $confirmed): GameOrder
    {
        $game = $package->game;

        if (! $game->is_active || ! $package->is_active) {
            throw new StockException('This package is not available now.');
        }

        $check = $this->verify($game, $playerId, $zone);

        if ($check['status'] === 'invalid') {
            throw new StockException('This player ID does not look right. Check it and try again.');
        }

        // After this a mistake is the buyer's: they have seen the name (or, with none to show, checked the ID themselves).
        if (! $confirmed) {
            throw new StockException($check['status'] === 'verified' ? 'Confirm that this is your name.' : 'Confirm that the ID is right.');
        }

        if (! preg_match('/^01[3-9]\d{8}$/', $phone)) {
            throw new StockException('Write a Bangladesh mobile number, like 01712345678.');
        }

        return GameOrder::create([
            'public_token' => Str::random(40), 'buyer_id' => $buyer?->id, 'game_id' => $game->id, 'game_package_id' => $package->id, 'game_name' => $game->name, 'package_name' => $package->name,
            'price' => $package->price, 'player_id' => trim($playerId), 'zone' => $game->needs_zone ? trim((string) $zone) : null, 'player_name' => $check['name'] ?? null, 'id_confirmed_at' => now(),
            'contact_phone' => $phone, 'status' => 'placed', 'refund' => 'none',
        ]);
    }

    /** The buyer says they paid, and gives the transaction id. We check it against our account. */
    public function claimPayment(GameOrder $order, string $method, string $ref): GameOrder
    {
        $ref = strtoupper(trim($ref));

        if (! in_array($order->status, ['placed'], true)) {
            throw new StockException('This order does not need a payment now.');
        }

        if (! in_array($method, self::METHODS, true)) {
            throw new StockException('Choose bKash, Nagad or Rocket.');
        }

        if (! preg_match('/^[A-Z0-9]{6,30}$/', $ref)) {
            throw new StockException('Write the transaction id exactly as it came in your message.');
        }

        if (GameOrder::where('pay_method', $method)->where('pay_ref', $ref)->where('id', '!=', $order->id)->exists()) {
            throw new StockException('This transaction id was already used for another order.');
        }

        $order->update(['status' => 'claimed', 'pay_method' => $method, 'pay_ref' => $ref, 'pay_note' => null, 'claimed_at' => now()]);

        foreach (User::withPermission('orders') as $staff) {
            Notice::create(['user_id' => $staff->id, 'kind' => 'game_payment', 'body' => mb_substr($order->number().': '.$method.' '.$ref.' for '.number_format((float) $order->price, 2), 0, 290)]);
        }

        return $order->fresh();
    }

    /** Before it is paid the buyer can drop it. Once paid, only the late rule (or we) can cancel, and the money goes back. */
    public function cancel(GameOrder $order, string $by = 'buyer'): GameOrder
    {
        if (in_array($order->status, ['placed', 'claimed'], true)) {
            $order->update(['status' => 'cancelled']);

            return $order->fresh();
        }

        if ($order->status !== 'paid') {
            throw new StockException('It is too late to cancel this order.');
        }

        if ($by === 'buyer' && ! $this->isLate($order)) {
            throw new StockException('Your top-up is being done. You can cancel if it takes too long.');
        }

        $order->update(['status' => 'cancelled', 'refund' => 'due']);

        return $order->fresh();
    }

    /** Paid and still not topped up after the set time: the buyer may cancel for a refund. */
    public function isLate(GameOrder $order): bool
    {
        return $order->status === 'paid' && $order->paid_at !== null && $order->paid_at->lte(now()->subMinutes($this->lateMinutes()));
    }

    // ------------------------------------------------------------------ our side

    /** We found the money in our account. The top-up is tried by the provider; with none, it waits for us. */
    public function acceptPayment(GameOrder $order, User $by): GameOrder
    {
        if ($order->status !== 'claimed') {
            throw new StockException('There is no payment waiting to be checked.');
        }

        $order->update(['status' => 'paid', 'paid_at' => now()]);
        $result = $this->provider->send($order);

        return match ($result['status']) {
            'done' => $this->markDone($order->fresh(), $result['ref'] ?? null),
            'failed' => $this->markFailed($order->fresh(), $result['reason'] ?? 'The top-up failed.'),
            default => $order->fresh(),
        };
    }

    /** The money is not there (or the amount is wrong): back to the buyer to try again. */
    public function rejectPayment(GameOrder $order, User $by, string $note): GameOrder
    {
        if ($order->status !== 'claimed' || mb_strlen(trim($note)) < 3) {
            throw new StockException('Say why the payment is not accepted.');
        }

        $order->update(['status' => 'placed', 'pay_method' => null, 'pay_ref' => null, 'pay_note' => mb_substr(trim($note), 0, 200), 'claimed_at' => null]);

        return $order->fresh();
    }

    /** We topped up by hand. */
    public function done(GameOrder $order, User $by, ?string $ref = null): GameOrder
    {
        if ($order->status !== 'paid') {
            throw new StockException('Only a paid order can be marked topped up.');
        }

        return $this->markDone($order, $ref);
    }

    /** The top-up did not go through: the buyer's money is to be refunded, and the order is kept in a list apart. */
    public function fail(GameOrder $order, User $by, string $reason): GameOrder
    {
        if ($order->status !== 'paid') {
            throw new StockException('Only a paid order can fail.');
        }

        if (mb_strlen(trim($reason)) < 3) {
            throw new StockException('Write why it failed.');
        }

        return $this->markFailed($order, trim($reason));
    }

    public function refunded(GameOrder $order, User $by): GameOrder
    {
        if ($order->refund !== 'due') {
            throw new StockException('There is no refund to make on this order.');
        }

        $order->update(['refund' => 'done', 'refunded_at' => now()]);

        if ($order->buyer) {
            $this->messages->send($order->buyer, 'game_refunded', ['name' => $order->number()], '/gaming/orders/'.$order->public_token);
        }

        return $order->fresh();
    }

    /** A top-up that is late: the buyer is told once, and can cancel for a refund. Returns how many were told. */
    public function watchLate(): int
    {
        $n = 0;

        GameOrder::where('status', 'paid')->whereNull('late_told_at')->where('paid_at', '<=', now()->subMinutes($this->lateMinutes()))->with('buyer')->each(function (GameOrder $o) use (&$n) {
            $o->update(['late_told_at' => now()]);
            if ($o->buyer) {
                $this->messages->send($o->buyer, 'game_late', ['name' => $o->number()], '/gaming/orders/'.$o->public_token);
            }
            foreach (User::withPermission('orders') as $staff) {
                Notice::create(['user_id' => $staff->id, 'kind' => 'game_late', 'body' => mb_substr($o->number().' is paid and not topped up yet', 0, 290)]);
            }
            $n++;
        });

        return $n;
    }

    // ------------------------------------------------------------------ lists and reports

    /** @return array<string, Collection<int, GameOrder>> */
    public function lists(): array
    {
        $recent = fn (array $status, string $col = 'id') => GameOrder::whereIn('status', $status)->latest($col)->limit(60)->get();

        return [
            'to_check' => GameOrder::where('status', 'claimed')->oldest('claimed_at')->get(),
            'to_top_up' => GameOrder::where('status', 'paid')->oldest('paid_at')->get(),
            'failed' => GameOrder::where(fn ($q) => $q->where('status', 'failed')->orWhere('refund', 'due'))->latest('id')->limit(60)->get(),
            'done' => $recent(['topped_up']),
        ];
    }

    /** Sales of topped-up orders, by game, in a month. @return array{orders: int, sales: float, by_game: Collection<int, array{game: string, orders: int, sales: float}>, failed: int} */
    public function sales(?string $month = null): array
    {
        $month ??= now('Asia/Dhaka')->format('Y-m');
        $rows = GameOrder::where('status', 'topped_up')->whereBetween('topped_up_at', [Carbon::parse($month.'-01', 'Asia/Dhaka')->startOfMonth()->utc(), Carbon::parse($month.'-01', 'Asia/Dhaka')->endOfMonth()->utc()])->get(['game_name', 'price']);

        return [
            'orders' => $rows->count(), 'sales' => round((float) $rows->sum('price'), 2),
            'by_game' => $rows->groupBy('game_name')->map(fn ($g, $name) => ['game' => $name, 'orders' => $g->count(), 'sales' => round((float) $g->sum('price'), 2)])->sortByDesc('sales')->values(),
            'failed' => GameOrder::where('status', 'failed')->count(),
        ];
    }

    // ------------------------------------------------------------------ helpers

    private function markDone(GameOrder $order, ?string $ref): GameOrder
    {
        $order->update(['status' => 'topped_up', 'topped_up_at' => now(), 'topup_ref' => $ref ? mb_substr($ref, 0, 60) : null]);

        if ($order->buyer) {
            $this->messages->send($order->buyer, 'game_done', ['name' => $order->number(), 'package' => $order->package_name, 'game' => $order->game_name], '/gaming/orders/'.$order->public_token);
        }

        return $order->fresh();
    }

    private function markFailed(GameOrder $order, string $reason): GameOrder
    {
        // Money was taken and the diamonds did not arrive: it is refunded, and the order is kept in the failed list.
        $order->update(['status' => 'failed', 'failed_at' => now(), 'fail_reason' => mb_substr($reason, 0, 200), 'refund' => 'due']);

        if ($order->buyer) {
            $this->messages->send($order->buyer, 'game_failed', ['name' => $order->number()], '/gaming/orders/'.$order->public_token);
        }

        foreach (User::withPermission('orders') as $staff) {
            Notice::create(['user_id' => $staff->id, 'kind' => 'game_failed', 'body' => mb_substr($order->number().' failed: '.$reason, 0, 290)]);
        }

        return $order->fresh();
    }
}
