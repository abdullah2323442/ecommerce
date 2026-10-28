<?php

namespace App\Services\Import;

use App\Enums\AccountType;
use App\Models\Address;
use App\Models\CustomerRate;
use App\Models\Medicine;
use App\Models\Setting;
use App\Models\Tier;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Pricing\TierAssigner;
use App\Services\Stock\StockException;
use App\Services\Stock\StockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Bringing in the shops a business already has (spec ch 28, note: before launch, existing shops' data must be entered, so a bulk import helps): a CSV file of
 * shops with their type, tier, area, address and, if wanted, the rates set by hand for them. Nothing is written until the person has seen a preview of every
 * line and chosen to go on; a line with a problem is shown with the reason and left out, and the rest go in. The phone number is how a shop is recognised: a
 * number already on the system updates that shop (its tier, area, shop name, address and rates) and never touches its name, type or password.
 */
class CustomerImport
{
    public const MAX_ROWS = 2000;

    public const MAX_BYTES = 1048576;

    public const COLUMNS = ['name', 'phone', 'type', 'tier', 'shop', 'area', 'district', 'address', 'email', 'medicine', 'discount_percent', 'fixed_price'];

    private const TYPES = ['public' => AccountType::Public, 'paikari' => AccountType::Paikari, 'shop' => AccountType::Paikari, 'wholesaler' => AccountType::Wholesaler, 'wholesale' => AccountType::Wholesaler];

    public function __construct(private TierAssigner $tiers, private ActivityLogger $activity, private StockService $stock) {}

    /** A small file to start from, with one example line, so nobody has to guess the columns. */
    public function template(): string
    {
        return implode("\n", [
            implode(',', self::COLUMNS),
            'Karim Pharmacy,01712345678,paikari,B,Karim Pharmacy,Mirpur,Dhaka,"House 4, Road 2",,,,',
            'Karim Pharmacy,01712345678,,,,,,,,Napa 500 mg,12,',
            'Habib Drug House,01812345678,paikari,,Habib Drug House,Pallabi,Dhaka,"Shop 7, Market Road",habib@example.com,,,',
        ])."\n";
    }

    /**
     * Read and check a file without writing anything.
     *
     * @return array{customers: array<string, array<string, mixed>>, lines: list<array<string, mixed>>, counts: array<string, int>}
     *
     * @throws StockException when the file cannot be read as a list of shops at all
     */
    public function preview(string $content): array
    {
        $rows = $this->read($content);
        $tiers = Tier::all()->keyBy(fn (Tier $t) => mb_strtolower(trim($t->name)));
        $medicines = $this->medicineIndex();
        $customers = [];
        $lines = [];

        foreach ($rows as $line => $row) {
            $errors = [];
            $phone = $this->phone((string) ($row['phone'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            $known = $customers[$phone] ?? null;
            $rate = $this->rate($row, $medicines, $errors);

            if (! preg_match('/^01[3-9]\d{8}$/', $phone)) {
                $errors[] = 'The phone number must be a Bangladesh mobile number, like 01712345678.';
            }

            if ($known !== null && $known['errors'] !== []) {
                $errors[] = __('The first line for this number (line {n}) has a problem, so this one cannot be used.', ['n' => $known['line']]);
            }

            if ($known === null && $errors === []) {
                $made = $this->customer($row, $phone, $name, $tiers, $errors);
            }

            if ($known !== null) {
                // A second line for a shop is only for rates; anything else on it would be silently dropped, so say so.
                if ($rate === null && $errors === []) {
                    $errors[] = __('This number is already on line {n}. Use further lines only for rates.', ['n' => $known['line']]);
                }

                if ($errors === [] && $rate !== null) {
                    $customers[$phone]['rates'][] = $rate;
                    $lines[] = $this->summary($line, $known['name'], $phone, 'rate', null, $rate);

                    continue;
                }

                $lines[] = $this->summary($line, $name ?: $known['name'], $phone, 'error', $errors);

                continue;
            }

            if ($errors !== []) {
                $customers[$phone] = ['line' => $line, 'name' => $name, 'errors' => $errors, 'rates' => []];
                $lines[] = $this->summary($line, $name, $phone, 'error', $errors);

                continue;
            }

            $made['line'] = $line;
            $made['rates'] = $rate ? [$rate] : [];
            $customers[$phone] = $made;
            $lines[] = $this->summary($line, $made['name'], $phone, $made['exists'] ? 'update' : 'new', null, $rate);
        }

        $ok = array_filter($customers, fn ($c) => $c['errors'] === []);
        $counts = [
            'lines' => count($lines),
            'new' => count(array_filter($ok, fn ($c) => ! $c['exists'])),
            'updated' => count(array_filter($ok, fn ($c) => $c['exists'])),
            'rates' => array_sum(array_map(fn ($c) => count($c['rates']), $ok)),
            'errors' => count(array_filter($lines, fn ($l) => $l['status'] === 'error')),
        ];

        return ['customers' => $ok, 'lines' => $lines, 'counts' => $counts];
    }

    /**
     * Write what the preview found. The data is read again from the stored file, so what goes in is exactly what was checked, checked now.
     *
     * @return array{new: int, updated: int, rates: int, skipped: int}
     */
    public function commit(string $content, User $by): array
    {
        $found = $this->preview($content);
        $done = ['new' => 0, 'updated' => 0, 'rates' => 0, 'skipped' => $found['counts']['errors']];

        DB::transaction(function () use ($found, $by, &$done) {
            foreach ($found['customers'] as $c) {
                $user = $c['exists'] ? User::findOrFail($c['user_id']) : $this->create($c);

                $this->place($user, $c, $by);
                $done[$c['exists'] ? 'updated' : 'new']++;

                foreach ($c['rates'] as $r) {
                    CustomerRate::updateOrCreate(['user_id' => $user->id, 'medicine_id' => $r['medicine_id']], ['discount_percent' => $r['percent'], 'fixed_price' => $r['fixed']]);
                    $done['rates']++;
                }
            }

            $this->activity->record('customers.imported', null, $done + ['lines' => $found['counts']['lines']], $by);
        });

        return $done;
    }

    // ------------------------------------------------------------------ reading

    /**
     * @return array<int, array<string, string>> the lines of the file by their line number in it, keyed by column name
     *
     * @throws StockException
     */
    private function read(string $content): array
    {
        if (strlen($content) > self::MAX_BYTES) {
            throw new StockException('The file is too big. Keep it under 1 MB, or send it in parts.');
        }

        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;

        if (! mb_check_encoding($content, 'UTF-8')) {
            throw new StockException('Save the file as CSV in UTF-8 and send it again.');
        }

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);

        $header = fgetcsv($handle, 0, ',', '"', '');

        if (! is_array($header) || $header === [null]) {
            throw new StockException('The file is empty.');
        }

        $header = array_map(fn ($h) => str_replace([' ', '-'], '_', mb_strtolower(trim((string) $h))), $header);
        $missing = array_diff(['name', 'phone'], $header);

        if ($missing !== []) {
            throw new StockException(__('The first line must name the columns, and these are missing: {list}.', ['list' => implode(', ', $missing)]));
        }

        $rows = [];
        $line = 1;

        while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $line++;

            if ($cells === [null] || trim(implode('', array_map(fn ($c) => (string) $c, $cells))) === '') {
                continue;
            }

            if (count($rows) >= self::MAX_ROWS) {
                throw new StockException(__('The file has more than {n} lines. Send it in parts.', ['n' => self::MAX_ROWS]));
            }

            $row = [];
            foreach ($header as $i => $key) {
                if ($key !== '') {
                    $row[$key] = trim((string) ($cells[$i] ?? ''));
                }
            }

            $rows[$line] = $row;
        }

        fclose($handle);

        if ($rows === []) {
            throw new StockException('The file has a first line but no shops under it.');
        }

        return $rows;
    }

    private function phone(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if (str_starts_with($digits, '880')) {
            $digits = '0'.substr($digits, 3);
        }

        // A number typed into a spreadsheet loses its leading zero.
        return strlen($digits) === 10 && str_starts_with($digits, '1') ? '0'.$digits : $digits;
    }

    /**
     * @param  array<string, string>  $row
     * @param  list<string>  $errors
     * @return array<string, mixed>
     */
    private function customer(array $row, string $phone, string $name, $tiers, array &$errors): array
    {
        $existing = User::where('phone', $phone)->first();
        $type = null;
        $typeText = mb_strtolower(trim($row['type'] ?? ''));

        if ($typeText !== '') {
            $type = self::TYPES[$typeText] ?? null;

            if ($type === null) {
                $errors[] = 'The type must be paikari, wholesaler or public.';
            }
        }

        if ($existing && ! in_array($existing->account_type, [AccountType::Public, AccountType::Paikari, AccountType::Wholesaler], true)) {
            $errors[] = 'This number belongs to a staff or other special account, so it cannot be imported.';
        }

        if (! $existing && mb_strlen($name) < 2) {
            $errors[] = 'Write the name of the shop or person.';
        }

        $tier = null;

        if (($row['tier'] ?? '') !== '') {
            $tier = $tiers->get(mb_strtolower(trim($row['tier'])));

            if ($tier === null) {
                $errors[] = __('There is no tier called {name}.', ['name' => $row['tier']]);
            }
        }

        $district = null;

        if (($row['district'] ?? '') !== '') {
            $district = collect(config('bd.districts'))->first(fn ($d) => mb_strtolower($d) === mb_strtolower(trim($row['district'])));

            if ($district === null) {
                $errors[] = __('{name} is not a district of Bangladesh.', ['name' => $row['district']]);
            }
        }

        $email = mb_strtolower(trim($row['email'] ?? ''));

        if ($email !== '') {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'The email is not written correctly.';
            } elseif (User::where('email', $email)->when($existing, fn ($q) => $q->whereKeyNot($existing->id))->exists()) {
                $errors[] = 'This email already belongs to another account.';
            }
        }

        return [
            'exists' => $existing !== null, 'user_id' => $existing?->id, 'name' => $existing?->name ?? $name, 'phone' => $phone, 'type' => $type?->value ?? ($existing ? null : AccountType::Paikari->value),
            'tier_id' => $tier?->id, 'shop' => ($row['shop'] ?? '') ?: null, 'area' => ($row['area'] ?? '') ?: null, 'district' => $district, 'address' => ($row['address'] ?? '') ?: null,
            'email' => $email ?: null, 'errors' => $errors, 'rates' => [],
        ];
    }

    /**
     * The rate on this line, if it has one; problems go into $errors.
     *
     * @param  array<string, string>  $row
     * @param  array<string, list<int>>  $medicines
     * @param  list<string>  $errors
     * @return array{medicine_id: int, label: string, percent: ?float, fixed: ?float}|null
     */
    private function rate(array $row, array $medicines, array &$errors): ?array
    {
        $name = trim($row['medicine'] ?? '');
        $percent = trim($row['discount_percent'] ?? '');
        $fixed = trim($row['fixed_price'] ?? '');

        if ($name === '' && $percent === '' && $fixed === '') {
            return null;
        }

        $ids = $medicines[mb_strtolower($name)] ?? [];

        if ($name === '' || count($ids) === 0) {
            $errors[] = __('No medicine is called {name}. Write the brand, then the strength, as in the medicine list.', ['name' => $name]);

            return null;
        }

        if (count($ids) > 1) {
            $found = Medicine::with('company:id,name')->whereIn('id', array_slice($ids, 0, 4))->get();
            $plain = $found->map(fn (Medicine $m) => trim($m->brand_name.' '.$m->strength.' '.$m->company?->name));
            // Two from one company are told apart by their form instead.
            $options = $found->map(fn (Medicine $m, int $i) => $plain->duplicates()->contains($plain[$i]) ? trim($m->brand_name.' '.$m->strength.' '.$m->form.' '.$m->company?->name) : $plain[$i])->unique()->take(3)->implode(', ');
            $errors[] = __('More than one medicine is called {name}. Add the company, like: {options}.', ['name' => $name, 'options' => $options]);

            return null;
        }

        if (($percent === '') === ($fixed === '')) {
            $errors[] = 'Give either a percent or a price for one piece, not both and not neither.';

            return null;
        }

        $medicine = Medicine::find($ids[0]);

        if ($percent !== '') {
            if (! is_numeric($percent) || (float) $percent < 0 || (float) $percent > 100) {
                $errors[] = 'The percent must be a number from 0 to 100.';

                return null;
            }
        } elseif (! is_numeric($fixed) || (float) $fixed <= 0) {
            $errors[] = 'The price must be a number above zero.';

            return null;
        } elseif ((float) $medicine->mrp_piece > 0 && (float) $fixed >= (float) $medicine->mrp_piece) {
            $errors[] = __('A price here is for one piece, and this medicine is printed at {price} a piece. Write a lower price, or use a percent.', ['price' => number_format((float) $medicine->mrp_piece, 2)]);

            return null;
        }

        $p = $percent !== '' ? round((float) $percent, 2) : null;
        $f = $fixed !== '' ? round((float) $fixed, 2) : null;

        if ($this->stock->rateWouldLose($medicine, $p, $f)) {
            $errors[] = 'This rate is below what this medicine cost. Raise it.';

            return null;
        }

        return ['medicine_id' => $medicine->id, 'label' => trim($medicine->brand_name.' '.$medicine->strength), 'percent' => $p, 'fixed' => $f];
    }

    /**
     * Every way a medicine can be written in the file: the brand, the brand and strength, then either of those with the form or the company added, which is
     * how two medicines with the same brand and strength from different companies are told apart.
     *
     * @return array<string, list<int>> lowercase text to the medicines it could mean
     */
    private function medicineIndex(): array
    {
        $index = [];

        foreach (Medicine::query()->with('company:id,name')->get(['id', 'company_id', 'brand_name', 'strength', 'form']) as $m) {
            $brand = mb_strtolower(trim($m->brand_name));
            $named = trim($brand.' '.mb_strtolower(trim((string) $m->strength)));
            $company = mb_strtolower(trim((string) $m->company?->name));
            $form = mb_strtolower(trim((string) $m->form));

            foreach (array_filter([$brand, $named, $form !== '' ? $named.' '.$form : null, $company !== '' ? $named.' '.$company : null, $company !== '' && $form !== '' ? $named.' '.$form.' '.$company : null]) as $key) {
                if (! in_array($m->id, $index[$key] ?? [], true)) {
                    $index[$key][] = $m->id;
                }
            }
        }

        return $index;
    }

    /**
     * @param  list<string>|null  $errors
     * @param  array<string, mixed>|null  $rate
     * @return array<string, mixed>
     */
    private function summary(int $line, string $name, string $phone, string $status, ?array $errors = null, ?array $rate = null): array
    {
        return [
            'line' => $line, 'name' => $name, 'phone' => $phone, 'status' => $status, 'problems' => array_map(fn ($e) => __($e), $errors ?? []),
            'rate' => $rate ? ($rate['percent'] !== null ? __('{label}: {n}% off', ['label' => $rate['label'], 'n' => rtrim(rtrim(number_format($rate['percent'], 2, '.', ''), '0'), '.')]) : __('{label}: {price} a piece', ['label' => $rate['label'], 'price' => number_format((float) $rate['fixed'], 2)])) : null,
        ];
    }

    // ------------------------------------------------------------------ writing

    /** @param  array<string, mixed>  $c */
    private function create(array $c): User
    {
        $user = new User(['name' => $c['name'], 'phone' => $c['phone'], 'email' => $c['email'] ?? $c['phone'].'@phone.invalid', 'password' => Hash::make(Str::random(40))]);
        $user->forceFill(['account_type' => AccountType::from($c['type'] ?? 'paikari')])->save();

        return $user;
    }

    /**
     * Shop name, area, tier and address, only where the file has something to say.
     *
     * @param  array<string, mixed>  $c
     */
    private function place(User $user, array $c, User $by): void
    {
        $changes = array_filter(['business_name' => $c['shop'], 'area' => $c['area']], fn ($v) => $v !== null);

        if ($user->wasRecentlyCreated === false && $c['email'] !== null && str_ends_with((string) $user->email, '@phone.invalid')) {
            $changes['email'] = $c['email'];
        }

        if ($changes !== []) {
            $user->forceFill($changes)->save();
        }

        $tier = $c['tier_id'] ?? null;

        if ($tier === null && $user->wasRecentlyCreated && $user->account_type === AccountType::Paikari) {
            $tier = (Tier::find(Setting::read('counter.default_tier_id')) ?? Tier::orderBy('discount_percent')->first())?->id;
        }

        if ($tier !== null && $tier !== $user->tier_id) {
            $this->tiers->assign($user, Tier::find($tier), $by);
        }

        if ($c['address'] !== null && ! Address::where('user_id', $user->id)->where('address', $c['address'])->exists()) {
            Address::create([
                'user_id' => $user->id, 'label' => $c['shop'] ?: __('Shop'), 'name' => $user->name, 'phone' => $user->phone, 'district' => $c['district'] ?? 'Dhaka', 'area' => $c['area'] ?? (string) $user->area, 'address' => $c['address'],
            ]);
        }
    }
}
