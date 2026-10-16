<?php

namespace Tests\Feature\Visibility;

use App\Enums\AccountType;
use App\Enums\Section;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pure rule tests for the account-type x section matrix (spec ch 05, golden rule G1).
 * If any of these change, the product decision changes too, so they must be deliberate.
 */
class SectionMatrixTest extends TestCase
{
    /** @return iterable<string, array{AccountType, Section, bool}> */
    public static function matrix(): iterable
    {
        $cases = [
            // Public buyer: public market, food, gaming, help. Never paikari or wholesale.
            [AccountType::Public, Section::Public, true],
            [AccountType::Public, Section::Food, true],
            [AccountType::Public, Section::Gaming, true],
            [AccountType::Public, Section::Help, true],
            [AccountType::Public, Section::Paikari, false],
            [AccountType::Public, Section::Wholesale, false],
            [AccountType::Public, Section::AdminPanel, false],

            // Paikari buyer: may use paikari and the shared sections, must NEVER reach wholesale.
            [AccountType::Paikari, Section::Paikari, true],
            [AccountType::Paikari, Section::Public, true],
            [AccountType::Paikari, Section::Wholesale, false],
            [AccountType::Paikari, Section::Mpo, false],
            [AccountType::Paikari, Section::AdminPanel, false],

            // Wholesaler: wholesale + shared, never paikari.
            [AccountType::Wholesaler, Section::Wholesale, true],
            [AccountType::Wholesaler, Section::Public, true],
            [AccountType::Wholesaler, Section::Paikari, false],

            // Single-purpose accounts see only their own section.
            [AccountType::Mpo, Section::Mpo, true],
            [AccountType::Mpo, Section::Public, false],
            [AccountType::Mpo, Section::Wholesale, false],
            [AccountType::FoodVendor, Section::Food, true],
            [AccountType::FoodVendor, Section::Public, false],
            [AccountType::Craftsman, Section::Craftsman, true],
            [AccountType::Craftsman, Section::Public, false],

            // Staff run the admin panel and paikari counter work; never wholesale or MPO.
            [AccountType::Staff, Section::AdminPanel, true],
            [AccountType::Staff, Section::Paikari, true],
            [AccountType::Staff, Section::Wholesale, false],
            [AccountType::Staff, Section::Mpo, false],

            // Admin (Siam) sees everything.
            [AccountType::Admin, Section::Wholesale, true],
            [AccountType::Admin, Section::Mpo, true],
            [AccountType::Admin, Section::AdminPanel, true],
        ];

        foreach ($cases as $i => [$type, $section, $expected]) {
            yield "{$type->value} -> {$section->value} = ".($expected ? 'allowed' : 'denied') => [$type, $section, $expected];
        }
    }

    #[DataProvider('matrix')]
    public function test_matrix(AccountType $type, Section $section, bool $expected): void
    {
        $this->assertSame($expected, $type->canSee($section));
    }

    public function test_paikari_never_sees_wholesale(): void
    {
        // The single most important rule on the platform (spec G1).
        $this->assertNotContains(Section::Wholesale, AccountType::Paikari->sections());
    }

    public function test_every_account_type_has_a_home_path(): void
    {
        foreach (AccountType::cases() as $type) {
            $this->assertStringStartsWith('/', $type->homePath());
        }
    }
}
