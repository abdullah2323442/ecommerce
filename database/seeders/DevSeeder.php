<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Models\Tier;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Local development data: one account per type, three tiers, a few paikari customers.
 *
 * Refuses to run outside the local environment so these well-known credentials can never
 * reach production. Run with: php artisan db:seed --class=DevSeeder
 *
 * Dev logins (local only):
 *   admin@siamsaqua.test   staff@siamsaqua.test   paikari@siamsaqua.test   wholesale@siamsaqua.test   visitor@siamsaqua.test
 *   password for all: DevPass-2026!
 */
class DevSeeder extends Seeder
{
    private const PASSWORD = 'DevPass-2026!';

    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('DevSeeder only runs in the local environment.');
        }

        $tiers = collect([['A', 10], ['B', 15], ['C', 20]])
            ->mapWithKeys(fn ($t) => [$t[0] => Tier::firstOrCreate(['name' => $t[0]], ['discount_percent' => $t[1]])]);

        $accounts = [
            ['Siam (Admin)', 'admin@siamsaqua.test', '01700000001', AccountType::Admin, null],
            ['Counter Staff', 'staff@siamsaqua.test', '01700000002', AccountType::Staff, null],
            ['Karim Pharmacy', 'paikari@siamsaqua.test', '01700000003', AccountType::Paikari, 'B'],
            ['Rahim Traders', 'wholesale@siamsaqua.test', '01700000004', AccountType::Wholesaler, 'A'],
            ['Nila Medico', 'nila@siamsaqua.test', '01700000005', AccountType::Paikari, 'C'],
            ['Habib Drug House', 'habib@siamsaqua.test', '01700000006', AccountType::Paikari, null],
            ['Public Visitor', 'visitor@siamsaqua.test', '01700000099', AccountType::Public, null],
        ];

        foreach ($accounts as [$name, $email, $phone, $type, $tier]) {
            User::updateOrCreate(['email' => $email], [
                'name' => $name,
                'phone' => $phone,
                'account_type' => $type,
                'tier_id' => $tier ? $tiers[$tier]->id : null,
                'password' => Hash::make(self::PASSWORD),
                'email_verified_at' => now(),
            ]);
        }

        // The bulk buyer: what they may see and how they buy (spec ch 14).
        User::where('email', 'wholesale@siamsaqua.test')->update([
            'business_name' => 'Rahim Traders', 'categories' => json_encode(['medicine']), 'moq' => 2, 'pay_cod' => true, 'pay_advance' => true,
        ]);
    }
}
