<?php

namespace App\Services\Medicines;

/**
 * Converts Bengali script to a Latin approximation so "নাপা" can match "Napa" (spec ch 11).
 * Phonetic and deliberately simple: it only has to land close enough for fuzzy matching.
 */
final class BanglaTransliterator
{
    private const CONSONANTS = [
        'ক' => 'k', 'খ' => 'kh', 'গ' => 'g', 'ঘ' => 'gh', 'ঙ' => 'ng',
        'চ' => 'c', 'ছ' => 'ch', 'জ' => 'j', 'ঝ' => 'jh', 'ঞ' => 'n',
        'ট' => 't', 'ঠ' => 'th', 'ড' => 'd', 'ঢ' => 'dh', 'ণ' => 'n',
        'ত' => 't', 'থ' => 'th', 'দ' => 'd', 'ধ' => 'dh', 'ন' => 'n',
        'প' => 'p', 'ফ' => 'f', 'ব' => 'b', 'ভ' => 'v', 'ম' => 'm',
        'য' => 'j', 'র' => 'r', 'ল' => 'l', 'শ' => 's', 'ষ' => 's', 'স' => 's',
        'হ' => 'h', 'ড়' => 'r', 'ঢ়' => 'r', 'য়' => 'y', 'ৎ' => 't',
    ];

    private const VOWELS = [
        'অ' => 'a', 'আ' => 'a', 'ই' => 'i', 'ঈ' => 'i', 'উ' => 'u', 'ঊ' => 'u',
        'ঋ' => 'ri', 'এ' => 'e', 'ঐ' => 'oi', 'ও' => 'o', 'ঔ' => 'ou',
    ];

    private const SIGNS = [
        'া' => 'a', 'ি' => 'i', 'ী' => 'i', 'ু' => 'u', 'ূ' => 'u',
        'ৃ' => 'ri', 'ে' => 'e', 'ৈ' => 'oi', 'ো' => 'o', 'ৌ' => 'ou',
    ];

    private const MARKS = ['ং' => 'ng', 'ঃ' => 'h', 'ঁ' => ''];

    private const VIRAMA = '্';

    public static function containsBangla(string $text): bool
    {
        return (bool) preg_match('/[\x{0980}-\x{09FF}]/u', $text);
    }

    public static function toLatin(string $text): string
    {
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $out = '';
        $n = count($chars);

        for ($i = 0; $i < $n; $i++) {
            $c = $chars[$i];
            // Nukta forms (ড় ঢ় য়) arrive as base + nukta; fold them first.
            if (($chars[$i + 1] ?? '') === "\u{09BC}") {
                $c .= "\u{09BC}";
                $i++;
            }

            if (isset(self::CONSONANTS[$c])) {
                $out .= self::CONSONANTS[$c];
                $next = $chars[$i + 1] ?? '';
                // A consonant with no vowel sign or virama carries the inherent "a" (except word-final).
                $hasFollower = isset(self::SIGNS[$next]) || $next === self::VIRAMA;
                $isWordEnd = $next === '' || ! self::isBangla($next);
                if (! $hasFollower && ! $isWordEnd && ! isset(self::MARKS[$next])) {
                    $out .= 'a';
                }
            } elseif (isset(self::VOWELS[$c])) {
                $out .= self::VOWELS[$c];
            } elseif (isset(self::SIGNS[$c])) {
                $out .= self::SIGNS[$c];
            } elseif (isset(self::MARKS[$c])) {
                $out .= self::MARKS[$c];
            } elseif ($c === self::VIRAMA) {
                continue;
            } else {
                $out .= $c;
            }
        }

        return $out;
    }

    private static function isBangla(string $c): bool
    {
        return (bool) preg_match('/[\x{0980}-\x{09FF}]/u', $c);
    }
}
