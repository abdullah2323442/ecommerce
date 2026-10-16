<?php

namespace App\Enums;

/**
 * The eight account types from spec ch 03. Visibility is the server-side source of truth
 * (spec ch 05 / golden rule G1-G2): the frontend may hide nothing it relies on for security.
 */
enum AccountType: string
{
    case Public = 'public';
    case Paikari = 'paikari';
    case Wholesaler = 'wholesaler';
    case Mpo = 'mpo';
    case FoodVendor = 'food_vendor';
    case Craftsman = 'craftsman';
    case Staff = 'staff';
    case Admin = 'admin';

    /** @return list<Section> */
    public function sections(): array
    {
        $shared = [Section::Public, Section::Food, Section::Gaming, Section::Help];

        return match ($this) {
            self::Public => $shared,
            self::Paikari => [Section::Public, Section::Paikari, Section::Food, Section::Gaming, Section::Help],
            self::Wholesaler => [Section::Public, Section::Wholesale, Section::Food, Section::Gaming, Section::Help],
            self::Mpo => [Section::Mpo],
            self::FoodVendor => [Section::Food],
            self::Craftsman => [Section::Craftsman],
            self::Staff => [Section::AdminPanel, Section::Paikari],
            self::Admin => Section::cases(),
        };
    }

    public function canSee(Section $section): bool
    {
        return in_array($section, $this->sections(), true);
    }

    /** Where this type lands after login (spec ACC-1). Paths are placeholders until their sections are built. */
    public function homePath(): string
    {
        return match ($this) {
            self::Public => '/',
            self::Paikari => '/paikari',
            self::Wholesaler => '/wholesale',
            self::Mpo => '/mpo',
            self::FoodVendor => '/food/vendor',
            self::Craftsman => '/craftsman',
            self::Staff, self::Admin => '/admin',
        };
    }
}
