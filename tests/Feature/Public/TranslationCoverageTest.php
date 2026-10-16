<?php

namespace Tests\Feature\Public;

use App\Enums\AccountType;
use App\Http\Middleware\SetLocale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Concerns\ResolvesInertiaProps;
use Tests\TestCase;

/**
 * The site is Bangla by default (spec ch 28). A string passed to t() or __() with no Bangla entry would
 * show as English in the middle of a Bangla page, so every literal key must be in lang/bn.json.
 */
class TranslationCoverageTest extends TestCase
{
    use RefreshDatabase;
    use ResolvesInertiaProps;

    /** @return array<string, string> */
    private function dictionary(): array
    {
        return json_decode((string) file_get_contents(lang_path('bn.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return list<string> */
    private function keysUsed(): array
    {
        $keys = [];
        $scan = function (string $dir, array $extensions, string $pattern) use (&$keys) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                if (! in_array($file->getExtension(), $extensions, true)) {
                    continue;
                }
                preg_match_all($pattern, (string) file_get_contents($file->getPathname()), $m);
                foreach ($m['key'] as $i => $key) {
                    $keys[] = str_replace("\\'", "'", $key);
                }
            }
        };

        // t('...'), t("..."), including calls with {placeholders}
        $scan(resource_path('js'), ['ts', 'tsx'], "/\\bt\\(\\s*(?:'(?<key>(?:[^'\\\\]|\\\\.)*)'|\"(?<key2>(?:[^\"\\\\]|\\\\.)*)\")/");
        // __('...') on the server
        $scan(app_path(), ['php'], "/__\\(\\s*'(?<key>(?:[^'\\\\]|\\\\.)*)'/");

        return array_values(array_unique(array_filter($keys, fn ($k) => $k !== '' && ! str_starts_with($k, 'auth.'))));
    }

    public function test_every_string_passed_to_the_translator_has_a_bangla_entry(): void
    {
        $dictionary = $this->dictionary();
        $missing = array_values(array_filter($this->keysUsed(), fn ($key) => ! array_key_exists($key, $dictionary)));

        $this->assertSame([], $missing, "These strings have no Bangla in lang/bn.json:\n".implode("\n", $missing));
    }

    public function test_placeholders_match_between_english_and_bangla(): void
    {
        foreach ($this->dictionary() as $english => $bangla) {
            preg_match_all('/[{:]\w+\}?/', $english, $a);
            preg_match_all('/[{:]\w+\}?/', $bangla, $b);
            sort($a[0]);
            sort($b[0]);

            $this->assertSame($a[0], $b[0], "Placeholders differ for: {$english}");
        }
    }

    public function test_no_translation_is_empty(): void
    {
        foreach ($this->dictionary() as $english => $bangla) {
            $this->assertNotSame('', trim($bangla), "Empty Bangla for: {$english}");
        }
    }

    public function test_bangla_is_the_default_language_and_the_dictionary_is_sent_to_the_browser(): void
    {
        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('locale', 'bn')
            ->where('translations.Medicines', 'ওষুধ'));
    }

    public function test_a_visitor_can_switch_to_english_and_it_is_remembered(): void
    {
        $this->from('/')->post('/locale', ['locale' => 'en'])->assertRedirect('/')->assertCookie(SetLocale::COOKIE, 'en');

        $this->withCookie(SetLocale::COOKIE, 'en')->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('locale', 'en')
            ->where('translations', []));
    }

    public function test_an_unsupported_language_is_rejected(): void
    {
        $this->post('/locale', ['locale' => 'fr'])->assertSessionHasErrors('locale');
        $this->post('/locale', [])->assertSessionHasErrors('locale');
    }

    /** @return list<string> the English dictionary keys the browser receives */
    private function dictionaryKeysFor(?User $user): array
    {
        return array_keys($this->inertiaProps('/', $user)['translations']);
    }

    private function mentions(array $keys, string $word): bool
    {
        foreach ($keys as $key) {
            if (preg_match('/\b'.preg_quote($word, '/').'\b/iu', $key)) {
                return true;
            }
        }

        return false;
    }

    public function test_a_guest_never_receives_the_words_for_restricted_sections(): void
    {
        // Golden rule G1: the dictionary goes to the browser with every page, so it must not leak them.
        $keys = $this->dictionaryKeysFor(null);

        foreach (['paikari', 'wholesale', 'mpo', 'craftsman'] as $word) {
            $this->assertFalse($this->mentions($keys, $word), "A guest's dictionary contains \"{$word}\".");
        }
    }

    public function test_a_paikari_buyer_gets_paikari_words_but_never_wholesale(): void
    {
        $keys = $this->dictionaryKeysFor(User::factory()->create(['account_type' => AccountType::Paikari]));

        $this->assertTrue($this->mentions($keys, 'paikari'));
        $this->assertFalse($this->mentions($keys, 'wholesale'), 'A paikari buyer must never see the word wholesale.');
    }

    public function test_a_wholesaler_gets_wholesale_words_but_never_paikari(): void
    {
        $keys = $this->dictionaryKeysFor(User::factory()->create(['account_type' => AccountType::Wholesaler]));

        $this->assertTrue($this->mentions($keys, 'wholesale'));
        $this->assertFalse($this->mentions($keys, 'paikari'));
    }

    public function test_the_admin_gets_every_word_so_the_admin_screens_read_in_bangla(): void
    {
        $keys = $this->dictionaryKeysFor(User::factory()->create(['account_type' => AccountType::Admin]));

        $this->assertTrue($this->mentions($keys, 'paikari'));
        $this->assertTrue($this->mentions($keys, 'wholesale'));
    }

    public function test_flash_messages_come_back_in_bangla(): void
    {
        $admin = User::factory()->create(['account_type' => AccountType::Admin]);

        $this->actingAs($admin)->post('/admin/tiers', ['name' => 'A', 'discount_percent' => 10]);

        $this->assertSame('টিয়ার A তৈরি হয়েছে।', session('status'));
    }
}
