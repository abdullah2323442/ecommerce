<?php

namespace App\Services\Sales;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Where a cart is kept (spec ch 27, web-app sync). A visitor's cart lives in the session, as before. A signed-in person's cart lives in the database under
 * their account, so the same cart is there on the website, on the phone and in the installed app. When someone signs in, what they had gathered as a
 * visitor is added to what their account already held; the same product is counted once with the larger number, never twice.
 */
class CartStore
{
    /** The session key and the database kind of each cart. */
    public const KINDS = ['cart' => 'shop', 'market_cart' => 'market'];

    /** @return list<array<string, mixed>> */
    public static function get(string $key): array
    {
        $user = auth()->user();

        if (! $user || ! isset(self::KINDS[$key])) {
            return array_values((array) session($key, []));
        }

        return array_values((array) json_decode((string) DB::table('account_carts')->where('user_id', $user->id)->where('kind', self::KINDS[$key])->value('items'), true));
    }

    /** @param list<array<string, mixed>> $items */
    public static function put(string $key, array $items): void
    {
        $user = auth()->user();

        if (! $user || ! isset(self::KINDS[$key])) {
            session([$key => array_values($items)]);

            return;
        }

        self::write($user->id, $key, array_values($items));
    }

    public static function forget(string $key): void
    {
        $user = auth()->user();

        if (! $user || ! isset(self::KINDS[$key])) {
            session()->forget($key);

            return;
        }

        DB::table('account_carts')->where('user_id', $user->id)->where('kind', self::KINDS[$key])->delete();
    }

    /** Signing in: what the visitor gathered joins the account's own cart, and the session is left empty. */
    public static function adopt(User $user): void
    {
        foreach (self::KINDS as $key => $kind) {
            $guest = array_values((array) session($key, []));

            if ($guest === []) {
                continue;
            }

            $saved = array_values((array) json_decode((string) DB::table('account_carts')->where('user_id', $user->id)->where('kind', $kind)->value('items'), true));
            self::write($user->id, $key, self::merge($saved, $guest, $key));
            session()->forget($key);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $saved
     * @param  list<array<string, mixed>>  $guest
     * @return list<array<string, mixed>>
     */
    private static function merge(array $saved, array $guest, string $key): array
    {
        $id = fn (array $i) => $key === 'cart' ? ($i['m'] ?? 0).'|'.($i['u'] ?? '') : ($i['l'] ?? 0).'|'.($i['v'] ?? 0);
        $out = [];

        foreach ($saved as $i) {
            $out[$id($i)] = $i;
        }

        foreach ($guest as $i) {
            $k = $id($i);
            $out[$k] = isset($out[$k]) ? [...$out[$k], 'q' => max($out[$k]['q'] ?? 0, $i['q'] ?? 0)] : $i;
        }

        return array_values($out);
    }

    /** @param list<array<string, mixed>> $items */
    private static function write(int $userId, string $key, array $items): void
    {
        $kind = self::KINDS[$key];

        if ($items === []) {
            DB::table('account_carts')->where('user_id', $userId)->where('kind', $kind)->delete();
        } else {
            DB::table('account_carts')->upsert([['user_id' => $userId, 'kind' => $kind, 'items' => json_encode($items), 'created_at' => now(), 'updated_at' => now()]], ['user_id', 'kind'], ['items', 'updated_at']);
        }
    }
}
