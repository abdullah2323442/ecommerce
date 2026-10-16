<?php

namespace App\Services\Audit;

use App\Models\Courier;
use App\Models\CustomerRate;
use App\Models\DeletedItem;
use App\Models\Expense;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tier;
use App\Models\User;
use App\Services\Stock\StockException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The "Deleted" bin (spec ch 25: "I deleted something by mistake"). A thing the owner removes is copied here whole, with the rows
 * that hang from it, before it goes, and stays for 30 days. Putting it back writes the same rows again under the same numbers.
 * Only things the owner can delete from a screen are kept: tiers, roles, couriers, expenses and manual customer rates.
 */
class Bin
{
    /** type => [model class, label of one row, child tables [name => [table, foreign key]]] */
    private const KINDS = [
        'tier' => [Tier::class, 'name', []],
        'role' => [Role::class, 'name', []],
        'courier' => [Courier::class, 'name', ['rates' => ['courier_rates', 'courier_id']]],
        'expense' => [Expense::class, 'category', []],
        'customer_rate' => [CustomerRate::class, 'id', []],
    ];

    public function days(): int
    {
        return max(1, (int) Setting::read('bin.days', 30));
    }

    /** Copy it into the bin. Call this just before deleting the row. */
    public function keep(Model $model, ?User $by = null): DeletedItem
    {
        $type = $this->typeOf($model);
        [, $labelField, $children] = self::KINDS[$type];

        $payload = ['attributes' => $model->getAttributes(), 'children' => []];
        foreach ($children as $name => [$table, $key]) {
            $payload['children'][$name] = DB::table($table)->where($key, $model->getKey())->get()->map(fn ($r) => (array) $r)->all();
        }

        $label = match ($type) {
            'expense' => trim($model->category.' '.$model->amount.' ('.$model->spent_on?->toDateString().')'),
            'customer_rate' => 'Manual rate for '.(User::whereKey($model->user_id)->value('name') ?? '#'.$model->user_id),
            default => (string) $model->getAttribute($labelField),
        };

        return DeletedItem::create(['type' => $type, 'label' => mb_substr($label, 0, 160), 'payload' => $payload, 'deleted_by' => $by?->id, 'created_at' => now()]);
    }

    /** Put it back. The same number, the same values, the rows that hung from it. */
    public function restore(DeletedItem $item): void
    {
        $kind = self::KINDS[$item->type] ?? throw new StockException('This cannot be put back.');
        [$class, , $children] = $kind;
        $attributes = $item->payload['attributes'];

        try {
            DB::transaction(function () use ($class, $attributes, $children, $item) {
                (new $class)->newQuery()->insert($attributes);

                foreach ($children as $name => [$table]) {
                    foreach ($item->payload['children'][$name] ?? [] as $row) {
                        DB::table($table)->insert($row);
                    }
                }

                $item->delete();
            });
        } catch (QueryException $e) {
            // Something with the same name was made since, or what it hung from is gone.
            throw new StockException('Something with the same name or place already exists now. Change that one first, then put this back.');
        }
    }

    /** Whatever has been in the bin longer than the set days is gone for good. Returns how many. */
    public function purge(): int
    {
        return DeletedItem::where('created_at', '<', now()->subDays($this->days()))->delete();
    }

    private function typeOf(Model $model): string
    {
        foreach (self::KINDS as $type => [$class]) {
            if ($model instanceof $class) {
                return $type;
            }
        }

        throw new StockException('This cannot be kept in the bin.');
    }

    /** What each type is called on the screen. */
    public static function labels(): array
    {
        return ['tier' => 'Tier', 'role' => 'Staff role', 'courier' => 'Courier', 'expense' => 'Expense', 'customer_rate' => 'Manual rate'];
    }
}
