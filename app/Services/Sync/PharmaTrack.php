<?php

namespace App\Services\Sync;

use App\Enums\AccountType;
use App\Jobs\PushToPharmaTrack;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Medicine;
use App\Models\MedicineBarcode;
use App\Models\Notice;
use App\Models\StockMovement;
use App\Models\SyncEvent;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Integrations\Integrations;
use App\Services\Integrations\SafeUrl;
use App\Services\Sales\InvoiceService;
use App\Services\Stock\StockException;
use App\Services\Stock\StockService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * The link with PharmaTrack, the owner's pharmacy software that shares this stock (spec ch 6 and 7, item 612): in-person sales are made there, online orders here,
 * and both must take from the same stock. The two programs talk in JSON over https, each side proving itself with a password (a bearer token):
 *
 *  - PharmaTrack asks for the stock (GET /webhooks/pharmatrack/stock), writes down its own sales here (POST .../sales) and sends a stock count (POST .../counts);
 *  - this site adds a medicine it could not supply to PharmaTrack's short list the moment staff mark it "not available" (POST {address}/integrations/ecommerce/short-list);
 *  - once PharmaTrack keeps stock itself, this site can also tell it about every finished online order (POST {address}/integrations/ecommerce/online-sales), with every receipt, return and correction (POST .../adjustments), so both programs show one stock.
 *
 * Every message carries the sender's own reference, and the same reference is never applied twice, so a message sent again after a bad line is harmless.
 * Quantities are in this site's stock units: boxes for a medicine sold by the box, pieces otherwise. See docs/pharmatrack-sync.md for the full contract.
 */
class PharmaTrack
{
    public const KEY = 'erp:pharmatrack';

    /** The switches for what this site sends, with what each is when the owner has not chosen. */
    private const SWITCHES = ['push_missed' => 'yes', 'push_online_sales' => 'yes'];

    /** True while a message from PharmaTrack is being applied here, so what it told us is never told back to it. */
    private static bool $applying = false;

    /** Where each kind of message goes on PharmaTrack, after the address the owner gave. */
    private const PATHS = ['short_list' => '/integrations/ecommerce/short-list', 'online_sale' => '/integrations/ecommerce/online-sales', 'adjustment' => '/integrations/ecommerce/adjustments', 'ping' => '/integrations/ecommerce/ping'];

    public function __construct(private Integrations $integrations, private StockService $stock, private ActivityLogger $activity) {}

    // ------------------------------------------------------------------ setup

    /** On and live: messages are accepted and sent. In test mode nothing moves. */
    public function enabled(): bool
    {
        $on = $this->integrations->active(self::KEY);

        return $on !== null && $on['mode'] === 'live';
    }

    /** Does this password match the one we gave PharmaTrack? */
    public function accepts(?string $token): bool
    {
        $on = $this->integrations->active(self::KEY);
        $known = (string) ($on['credentials']['inbound_token'] ?? '');

        return $on !== null && $on['mode'] === 'live' && $known !== '' && is_string($token) && $token !== '' && hash_equals($known, $token);
    }

    public static function newToken(): string
    {
        return 'pt_'.Str::random(48);
    }

    /** Where to send, and with what key, for one kind of message. Null when the link is off, in test mode, has no address, or that kind is switched off. */
    private function outbound(string $switch): ?array
    {
        $on = $this->integrations->active(self::KEY);

        if ($on === null || $on['mode'] !== 'live' || ($on['settings'][$switch] ?? self::SWITCHES[$switch]) !== 'yes') {
            return null;
        }

        $base = rtrim((string) ($on['settings']['base_url'] ?? ''), '/');
        $key = (string) ($on['credentials']['api_key'] ?? '');

        return $base !== '' && $key !== '' && SafeUrl::ok($base) ? ['base' => $base, 'key' => $key] : null;
    }

    // ------------------------------------------------------------------ what PharmaTrack asks for

    /**
     * The stock, a page at a time. "updated_since" gives only medicines whose stock moved since then.
     *
     * @return array{data: list<array<string, mixed>>, next_page: ?int}
     */
    public function stockPage(int $page, int $perPage, ?string $since, array $ids = []): array
    {
        $perPage = max(1, min(1000, $perPage));
        $query = Medicine::query()->where('is_active', true)->with(['barcodes:id,medicine_id,code', 'company:id,name'])->orderBy('id');

        if ($since !== null) {
            try {
                $at = Carbon::parse($since);
                $query->whereIn('id', StockMovement::where('created_at', '>=', $at)->select('medicine_id'));
            } catch (\Throwable) {
                // An unreadable date means "everything".
            }
        }

        if ($ids !== []) {
            $query->whereIn('id', array_slice(array_map('intval', $ids), 0, 500));
        }

        $rows = $query->paginate($perPage, ['*'], 'page', max(1, $page));

        return [
            'data' => $rows->getCollection()->map(fn (Medicine $m) => [
                'medicine_id' => $m->id, 'barcodes' => $m->barcodes->pluck('code')->all(), 'brand' => $m->brand_name, 'strength' => $m->strength, 'form' => $m->form,
                'company' => $m->company?->name, 'pieces_per_unit' => round(1 / InvoiceService::fraction($m, 'piece'), 4),
                'unit' => $m->packaging_type === 'box' ? 'box' : 'piece', 'on_hand' => round($this->stock->onHand($m), 4), 'mrp_unit' => round(StockService::unitMrp($m), 2),
            ])->values()->all(),
            'next_page' => $rows->hasMorePages() ? $rows->currentPage() + 1 : null,
        ];
    }

    // ------------------------------------------------------------------ what PharmaTrack tells us

    /**
     * A sale made in PharmaTrack: write it down here, taking the goods off this stock. It is never refused (it already happened): if this stock holds less than
     * was sold, what there is is taken and the rest is reported, so the owner can see the two programs disagree.
     *
     * @param  array<string, mixed>  $in  ref, sold_at, lines[]{medicine_id|barcode, units, unit_price?}
     * @return array{status: string, applied: list<array<string, mixed>>, short: list<array<string, mixed>>, unknown: list<string>}
     *
     * @throws StockException
     */
    public function receiveSale(array $in): array
    {
        $ref = $this->ref($in);
        $lines = $this->lines($in, 'units');

        return $this->once('in', 'sale', $ref, function (SyncEvent $event) use ($lines) {
            $applied = $short = $unknown = [];

            foreach ($lines as $line) {
                $medicine = $this->find($line);

                if ($medicine === null) {
                    $unknown[] = $this->label($line);

                    continue;
                }

                $have = $this->stock->onHand($medicine);
                $asked = $this->toStock($medicine, $line, 'units');
                $take = min($asked, max(0.0, $have));
                $price = isset($line['unit_price']) && is_numeric($line['unit_price']) ? $this->priceToStock($medicine, $line, (float) $line['unit_price']) : StockService::unitMrp($medicine);

                if ($take > 0) {
                    $this->stock->sell($medicine, $take, $price, StockService::SALE_EXTERNAL, $this->system());
                    $applied[] = ['medicine_id' => $medicine->id, 'units' => $take];
                }

                if ($take + 0.00001 < $asked) {
                    $short[] = ['medicine_id' => $medicine->id, 'brand' => $medicine->brand_name, 'sold' => $asked, 'here' => round($have, 4)];
                }
            }

            $status = $short !== [] || $unknown !== [] ? 'partial' : 'ok';
            $parts = [$applied === [] ? ['Nothing taken off.'] : ['Lines taken off: {n}.', ['n' => count($applied)]]];

            foreach ($short as $s) {
                $parts[] = ['Short on {brand}: sold {sold}, here {here}.', ['brand' => $s['brand'], 'sold' => $s['sold'], 'here' => $s['here']]];
            }

            if ($unknown !== []) {
                $parts[] = ['Not found: {list}.', ['list' => implode(', ', $unknown)]];
            }

            $event->update(['status' => $status, 'summary' => SyncEvent::note($parts)]);

            if ($short !== [] || $unknown !== []) {
                $this->tellOwners('PharmaTrack sold more than this stock holds, or a medicine was not found. Open PharmaTrack in Connections to see which.');
            }

            return ['status' => $status, 'applied' => $applied, 'short' => $short, 'unknown' => $unknown];
        });
    }

    /**
     * A stock count made in PharmaTrack: the on-hand figure for each medicine is set to it, as a correction with its reason written down.
     *
     * @param  array<string, mixed>  $in  ref, lines[]{medicine_id|barcode, on_hand}
     * @return array{status: string, applied: list<array<string, mixed>>, unknown: list<string>, failed: list<array<string, mixed>>}
     *
     * @throws StockException
     */
    public function receiveCount(array $in): array
    {
        $ref = $this->ref($in);
        $lines = $this->lines($in, 'on_hand', allowZero: true);

        return $this->once('in', 'count', $ref, function (SyncEvent $event) use ($lines, $ref) {
            $applied = $unknown = $failed = [];

            foreach ($lines as $line) {
                $medicine = $this->find($line);

                if ($medicine === null) {
                    $unknown[] = $this->label($line);

                    continue;
                }

                try {
                    $moved = $this->stock->correct($medicine, $this->toStock($medicine, $line, 'on_hand'), 'Count from PharmaTrack', $this->system(), $ref);
                    $applied[] = ['medicine_id' => $medicine->id, 'change' => round($moved, 4)];
                } catch (StockException $e) {
                    $failed[] = ['medicine_id' => $medicine->id, 'why' => $e->getMessage()];
                }
            }

            $status = $unknown !== [] || $failed !== [] ? 'partial' : 'ok';
            $parts = [['Counts applied: {n}.', ['n' => count($applied)]]];

            if ($unknown !== []) {
                $parts[] = ['Not found: {list}.', ['list' => implode(', ', $unknown)]];
            }

            foreach ($failed as $f) {
                $parts[] = ['Could not set: {why}', ['why' => mb_substr($f['why'], 0, 120)]];
            }

            $event->update(['status' => $status, 'summary' => SyncEvent::note($parts)]);

            return ['status' => $status, 'applied' => $applied, 'unknown' => $unknown, 'failed' => $failed];
        });
    }

    // ------------------------------------------------------------------ what we tell PharmaTrack

    /** A finished online order: PharmaTrack is told so it takes the same goods off its shelf. Nothing is done when the link is off or has no address. */
    public function announceOnlineSale(Invoice $invoice): void
    {
        if ($invoice->channel !== 'online' || $this->outbound('push_online_sales') === null) {
            return;
        }

        $lines = $invoice->lines()->whereNull('removed_at')->whereIn('verify_status', ['full', 'partial'])->where('stock_units', '>', 0)->get()->map(fn ($l) => [
            'medicine_id' => $l->medicine_id, 'barcodes' => MedicineBarcode::where('medicine_id', $l->medicine_id)->pluck('code')->all(), 'units' => (float) $l->stock_units, 'unit_price' => (float) $l->unit_price,
        ])->values()->all();
        $lines = array_map(fn ($l) => $this->inPieces($l), $lines);

        if ($lines === []) {
            return;
        }

        try {
            $event = SyncEvent::create(['direction' => 'out', 'kind' => 'online_sale', 'ref' => 'invoice:'.$invoice->number, 'status' => 'pending', 'summary' => SyncEvent::note([['Lines: {n}.', ['n' => count($lines)]]]), 'payload' => ['ref' => 'invoice:'.$invoice->number, 'sold_at' => ($invoice->completed_at ?? now())->toIso8601String(), 'lines' => $lines]]);
        } catch (UniqueConstraintViolationException) {
            return; // already told
        }

        PushToPharmaTrack::dispatch($event->id);
    }

    /** A line described the way PharmaTrack understands it: the medicine by name, and the quantity and price counted in pieces. @param array<string, mixed> $line @return array<string, mixed> */
    private function inPieces(array $line): array
    {
        $m = Medicine::with('company:id,name')->find($line['medicine_id']);

        if ($m === null) {
            return $line;
        }

        $perUnit = 1 / InvoiceService::fraction($m, 'piece');

        return ['name' => $m->brand_name, 'strength' => $m->strength, 'company' => $m->company?->name, 'unit' => 'piece', 'units' => self::wholePieces((float) $line['units'] * $perUnit)]
            + (isset($line['unit_price']) ? ['unit_price' => round((float) $line['unit_price'] / $perUnit, 4)] : []) + $line;
    }

    /**
     * Pieces are whole things. A box cannot hold one piece exactly in a few decimals (1 of 120 is 0.0083333), so a figure a hair away from a whole number
     * is that whole number; left alone, the two programs would drift apart by a fraction of a piece with every change.
     */
    public static function wholePieces(float $pieces): float
    {
        $whole = round($pieces);

        return abs($pieces - $whole) < 0.05 ? $whole : round($pieces, 4);
    }

    /**
     * Goods in or out here that PharmaTrack must also see (a purchase received, a customer return, an admin correction): one message, in pieces, positive adds.
     * Nothing is sent for what PharmaTrack itself just told us, and a failure never undoes the change.
     */
    public function announceChange(Medicine $medicine, float $stockUnits, string $reason, string $ref): void
    {
        if (self::$applying || abs($stockUnits) < 0.00005 || $this->outbound('push_online_sales') === null) {
            return;
        }

        $item = $this->inPieces(['medicine_id' => $medicine->id, 'units' => $stockUnits]);
        unset($item['medicine_id'], $item['barcodes']);

        try {
            $event = SyncEvent::create(['direction' => 'out', 'kind' => 'adjustment', 'ref' => mb_substr($ref, 0, 80), 'status' => 'pending', 'summary' => SyncEvent::note([['Stock change: {name}.', ['name' => trim($medicine->brand_name.' '.$medicine->strength)]]]), 'payload' => ['ref' => mb_substr($ref, 0, 80), 'reason' => mb_substr($reason, 0, 150), 'lines' => [$item]]]);
        } catch (UniqueConstraintViolationException) {
            return;
        }

        PushToPharmaTrack::dispatch($event->id);
    }

    /**
     * A medicine staff marked "not available" on an order (spec ch 7, step 5): PharmaTrack is asked to add it to its short list, so it can be bought later
     * without anyone writing it by hand. One line is told once, however many times staff change their mind about it.
     */
    public function announceMissed(InvoiceLine $line, float $quantity): void
    {
        if ($line->medicine_id === null || $this->outbound('push_missed') === null) {
            return;
        }

        $medicine = Medicine::with('company:id,name')->find($line->medicine_id);

        if ($medicine === null) {
            return;
        }

        $item = ['name' => $medicine->brand_name, 'strength' => $medicine->strength, 'form' => $medicine->form, 'company' => $medicine->company?->name, 'unit' => $line->unit, 'quantity' => round($quantity, 2)];

        try {
            $event = SyncEvent::create(['direction' => 'out', 'kind' => 'short_list', 'ref' => 'line:'.$line->id, 'status' => 'pending', 'summary' => SyncEvent::note([['Short list: {name}.', ['name' => trim($medicine->brand_name.' '.$medicine->strength)]]]), 'payload' => ['ref' => 'line:'.$line->id, 'items' => [$item]]]);
        } catch (UniqueConstraintViolationException) {
            return; // already told
        }

        PushToPharmaTrack::dispatch($event->id);
    }

    /** Send one message to PharmaTrack (the queue calls this). Returns whether it was accepted; the answer is written on the event. */
    public function push(SyncEvent $event): bool
    {
        $out = $this->outbound($event->kind === 'short_list' ? 'push_missed' : 'push_online_sales');

        if ($out === null) {
            $event->update(['status' => 'failed', 'error' => SyncEvent::note([['The link with PharmaTrack is off or has no address and key.']])]);

            return false;
        }

        $event->increment('attempts');

        try {
            $res = Http::timeout(15)->withToken($out['key'])->acceptJson()->withoutRedirecting()->post($out['base'].self::PATHS[$event->kind === 'online_sale' ? 'online_sale' : $event->kind], (array) $event->payload);
        } catch (ConnectionException) {
            $event->update(['status' => 'failed', 'error' => SyncEvent::note([['Could not reach PharmaTrack.']])]);

            return false;
        }

        if ($res->successful()) {
            $event->update(['status' => 'ok', 'error' => null]);

            return true;
        }

        $event->update(['status' => 'failed', 'error' => SyncEvent::note([['PharmaTrack answered {n}.', ['n' => $res->status()]]])]);

        return false;
    }

    /** Send again every message PharmaTrack did not accept. @return int how many were queued */
    public function retryFailed(): int
    {
        $failed = SyncEvent::where('direction', 'out')->where('status', 'failed')->orderBy('id')->limit(200)->get();

        foreach ($failed as $event) {
            $event->update(['status' => 'pending']);
            PushToPharmaTrack::dispatch($event->id);
        }

        return $failed->count();
    }

    /** The test button: does PharmaTrack answer at its address with our key? @return array{ok: bool, note: string} */
    public function probe(array $values): array
    {
        $base = rtrim((string) ($values['settings']['base_url'] ?? ''), '/');
        $key = (string) ($values['credentials']['api_key'] ?? '');

        if ($base === '') {
            return ['ok' => true, 'note' => __('PharmaTrack can write to this site with the token. No address is set, so nothing is sent to PharmaTrack yet.')];
        }

        if (! SafeUrl::ok($base) || $key === '') {
            return ['ok' => false, 'note' => __('The address must be https and a public website, and the key is needed.')];
        }

        try {
            $res = Http::timeout(10)->withToken($key)->acceptJson()->withoutRedirecting()->get($base.self::PATHS['ping']);
        } catch (ConnectionException) {
            return ['ok' => false, 'note' => __('Could not reach PharmaTrack.')];
        }

        return $res->successful() ? ['ok' => true, 'note' => __('PharmaTrack answered.')] : ['ok' => false, 'note' => __('PharmaTrack answered {n}. Check the address and the key.', ['n' => $res->status()])];
    }

    /**
     * Goods in or out on PharmaTrack's side (a receipt, a return, a loss): the same change is made here. Positive adds to a lot already in stock, negative takes off;
     * a medicine with no purchase here yet cannot take goods in (it has no cost), so that line is reported and the owner enters it through a purchase.
     *
     * @param  array<string, mixed>  $in  ref, reason?, lines[]{medicine_id|barcode|name, unit?, units (signed)}
     * @return array{status: string, applied: list<array<string, mixed>>, unknown: list<string>, failed: list<array<string, mixed>>}
     *
     * @throws StockException
     */
    public function receiveAdjustment(array $in): array
    {
        $ref = $this->ref($in);
        $lines = $this->lines($in, 'units', signed: true);
        $why = mb_substr((string) ($in['reason'] ?? 'From PharmaTrack'), 0, 150);

        return $this->once('in', 'adjustment', $ref, function (SyncEvent $event) use ($lines, $ref, $why) {
            $applied = $unknown = $failed = [];

            foreach ($lines as $line) {
                $medicine = $this->find($line);

                if ($medicine === null) {
                    $unknown[] = $this->label($line);

                    continue;
                }

                $delta = $this->toStock($medicine, $line, 'units');
                $have = $this->stock->onHand($medicine);

                try {
                    $moved = $this->stock->correct($medicine, max(0.0, $have + $delta), 'pharmatrack', $this->system(), $why.' ('.$ref.')');
                    $applied[] = ['medicine_id' => $medicine->id, 'change' => round($moved, 4)];
                } catch (StockException $e) {
                    $failed[] = ['medicine_id' => $medicine->id, 'why' => $e->getMessage()];
                }
            }

            $status = $unknown !== [] || $failed !== [] ? 'partial' : 'ok';
            $parts = [['Changes applied: {n}.', ['n' => count($applied)]]];

            if ($unknown !== []) {
                $parts[] = ['Not found: {list}.', ['list' => implode(', ', $unknown)]];
            }

            foreach ($failed as $f) {
                $parts[] = ['Could not set: {why}', ['why' => mb_substr($f['why'], 0, 120)]];
            }

            $event->update(['status' => $status, 'summary' => SyncEvent::note($parts)]);

            return ['status' => $status, 'applied' => $applied, 'unknown' => $unknown, 'failed' => $failed];
        });
    }

    /** A medicine's quantity as this site's stock unit: a piece, strip or box from PharmaTrack becomes its share of one unit here. With no unit named it is already in stock units. @param array<string, mixed> $line */
    private function toStock(Medicine $m, array $line, string $key): float
    {
        $n = (float) $line[$key];

        return match ($line['unit'] ?? null) {
            'piece', 'pcs' => round($n * InvoiceService::fraction($m, 'piece'), 6),
            'strip' => round($n * InvoiceService::fraction($m, 'strip'), 6),
            'box' => round($n * InvoiceService::fraction($m, 'box'), 6),
            default => $n,
        };
    }

    /** The same for a price: PharmaTrack prices one piece, a stock unit here is worth that many pieces. @param array<string, mixed> $line */
    private function priceToStock(Medicine $m, array $line, float $price): float
    {
        $fraction = match ($line['unit'] ?? null) {
            'piece', 'pcs' => InvoiceService::fraction($m, 'piece'),
            'strip' => InvoiceService::fraction($m, 'strip'),
            default => 1.0,
        };

        return $fraction > 0 ? round($price / $fraction, 4) : $price;
    }

    /** @param array<string, mixed> $line */
    private function label(array $line): string
    {
        return trim((string) ($line['name'] ?? '').' '.(string) ($line['strength'] ?? '')) ?: (string) ($line['barcode'] ?? $line['medicine_id'] ?? '?');
    }

    // ------------------------------------------------------------------ the owner's view

    /** @return array<string, int> */
    public function counts(): array
    {
        $by = SyncEvent::query()->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');

        return ['ok' => (int) ($by['ok'] ?? 0), 'partial' => (int) ($by['partial'] ?? 0), 'failed' => (int) ($by['failed'] ?? 0), 'pending' => (int) ($by['pending'] ?? 0)];
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Do a message once: the event row is made first, so the same reference arriving twice (or at once) is applied one time and answered the same way.
     *
     * @param  callable(SyncEvent): array<string, mixed>  $do
     * @return array<string, mixed>
     */
    private function once(string $direction, string $kind, string $ref, callable $do): array
    {
        try {
            $event = SyncEvent::create(['direction' => $direction, 'kind' => $kind, 'ref' => $ref, 'status' => 'pending', 'attempts' => 1]);
        } catch (UniqueConstraintViolationException) {
            $before = SyncEvent::where(['direction' => $direction, 'kind' => $kind, 'ref' => $ref])->firstOrFail();

            // A message that failed before can be sent again once the cause is fixed; one that was applied is never applied twice.
            if ($before->status !== 'failed') {
                return ['status' => 'duplicate', 'earlier' => $before->status, 'applied' => [], 'short' => [], 'unknown' => []];
            }

            $event = $before;
            $event->update(['status' => 'pending', 'error' => null]);
            $event->increment('attempts');
        }

        try {
            self::$applying = true;

            return DB::transaction(fn () => $do($event));
        } catch (\Throwable $e) {
            $event->update(['status' => 'failed', 'error' => SyncEvent::note([['{why}', ['why' => mb_substr($e->getMessage(), 0, 200)]]])]);

            throw $e;
        } finally {
            self::$applying = false;
        }
    }

    /** @throws StockException */
    private function ref(array $in): string
    {
        $ref = trim((string) ($in['ref'] ?? ''));

        if ($ref === '' || mb_strlen($ref) > 80) {
            throw new StockException('Every message needs its own reference, up to 80 letters.');
        }

        return $ref;
    }

    /**
     * @param  array<string, mixed>  $in
     * @return list<array<string, mixed>>
     *
     * @throws StockException
     */
    private function lines(array $in, string $quantity, bool $allowZero = false, bool $signed = false): array
    {
        $lines = array_values(array_filter((array) ($in['lines'] ?? []), 'is_array'));

        if ($lines === [] || count($lines) > 500) {
            throw new StockException('Send between 1 and 500 lines.');
        }

        foreach ($lines as $l) {
            $n = $l[$quantity] ?? null;

            $bad = ! is_numeric($n) || abs((float) $n) > 100000000 || ($signed ? (float) $n == 0.0 : ((float) $n < 0 || (! $allowZero && (float) $n <= 0)));

            if ($bad || (empty($l['barcode']) && empty($l['medicine_id']) && empty($l['name']))) {
                throw new StockException('Each line needs a barcode, a medicine_id or a name, and a '.$quantity.' number.');
            }
        }

        return $lines;
    }

    /** @param array<string, mixed> $line */
    private function find(array $line): ?Medicine
    {
        if (! empty($line['medicine_id'])) {
            return Medicine::find((int) $line['medicine_id']);
        }

        if (! empty($line['barcode'])) {
            $code = MedicineBarcode::clean((string) $line['barcode']);

            return $code === '' ? null : MedicineBarcode::where('code', $code)->first()?->medicine;
        }

        return $this->byName($line);
    }

    /**
     * By name when nothing else identifies it: the brand must match (ignoring case) and, when given, so must the strength; when several still share it the company
     * narrows it down. Only one clear answer counts: a guess could take goods off the wrong medicine.
     *
     * @param  array<string, mixed>  $line
     */
    private function byName(array $line): ?Medicine
    {
        $squash = fn (string $t) => preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($t)) ?? '';
        $found = Medicine::with('company:id,name')->whereRaw('lower(brand_name) = ?', [mb_strtolower(trim((string) ($line['name'] ?? '')))])->get();

        if (filled($line['strength'] ?? null)) {
            $found = $found->filter(fn (Medicine $m) => $squash((string) $m->strength) === $squash((string) $line['strength']));
        }

        if ($found->count() > 1 && filled($line['company'] ?? null)) {
            $wanted = $squash((string) $line['company']);
            $found = $found->filter(function (Medicine $m) use ($squash, $wanted) {
                $name = $squash((string) $m->company?->name);

                return $name !== '' && ($name === $wanted || str_contains($name, $wanted) || str_contains($wanted, $name));
            });
        }

        return $found->count() === 1 ? $found->first() : null;
    }

    /** The account that PharmaTrack's changes are written under: the owner's. @throws StockException */
    private function system(): User
    {
        return User::where('account_type', AccountType::Admin->value)->orderBy('id')->first() ?? throw new StockException('There is no owner account to write the changes under.');
    }

    private function tellOwners(string $text): void
    {
        foreach (User::withPermission('stock')->pluck('id') as $id) {
            Notice::create(['user_id' => $id, 'kind' => 'stock_mismatch', 'body' => mb_substr($text, 0, 290)]);
        }
    }
}
