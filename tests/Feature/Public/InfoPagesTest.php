<?php

namespace Tests\Feature\Public;

use App\Enums\AccountType;
use App\Http\Middleware\SetLocale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 27: terms, privacy, return policy and questions, open to everyone and silent about restricted sections. */
class InfoPagesTest extends TestCase
{
    use RefreshDatabase;

    private const PAGES = ['/terms' => 'Terms of service', '/privacy' => 'Privacy policy', '/return-policy' => 'Return policy', '/faq' => 'Questions and answers'];

    private function in(string $locale, string $path)
    {
        return $this->withCookie(SetLocale::COOKIE, $locale)->get($path);
    }

    public function test_every_page_opens_for_a_visitor_in_both_languages(): void
    {
        foreach (self::PAGES as $path => $title) {
            $this->in('en', $path)->assertOk()->assertInertia(fn (Assert $p) => $p->component('info')->has('sections')->where('title', $title));
            $this->in('bn', $path)->assertOk()->assertInertia(fn (Assert $p) => $p->where('title', fn ($v) => $v !== $title && $v !== ''));
        }
    }

    public function test_no_page_names_a_part_of_the_platform_a_visitor_may_not_know_about(): void
    {
        foreach (['bn', 'en'] as $locale) {
            foreach (array_keys(self::PAGES) as $path) {
                $page = $this->in($locale, $path)->assertOk()->viewData('page')['props'];
                $text = mb_strtolower(json_encode(['title' => $page['title'], 'intro' => $page['intro'], 'sections' => $page['sections']], JSON_UNESCAPED_UNICODE));

                foreach (['paikari', 'wholesale', 'mpo', 'craftsman', 'পাইকারি', 'পাইকারী'] as $word) {
                    $this->assertStringNotContainsString($word, $text, "{$path} ({$locale}) mentions {$word}");
                }
            }
        }
    }

    public function test_the_return_policy_says_medicine_is_generally_not_returnable(): void
    {
        $this->in('en', '/return-policy')->assertInertia(fn (Assert $p) => $p->where('sections.0.body.0', fn ($v) => str_contains($v, 'generally not returnable')));
    }

    public function test_the_questions_page_gives_each_answer_after_a_dash_so_the_page_can_split_them(): void
    {
        $props = $this->in('en', '/faq')->viewData('page')['props'];

        foreach ($props['sections'] as $section) {
            foreach ($section['body'] as $line) {
                $this->assertStringContainsString(' — ', $line);
            }
        }
    }

    public function test_the_chat_button_and_licence_appear_only_when_the_owner_has_set_them(): void
    {
        $this->get('/faq')->assertInertia(fn (Assert $p) => $p->where('brand.whatsapp', null)->where('brand.messenger', null)->where('brand.drug_license', null));

        config(['brand.whatsapp' => '8801700000000', 'brand.drug_license' => 'DL-12345']);

        $this->get('/faq')->assertInertia(fn (Assert $p) => $p->where('brand.whatsapp', '8801700000000')->where('brand.drug_license', 'DL-12345'));
    }

    public function test_signed_in_customers_see_the_same_pages(): void
    {
        foreach ([AccountType::Public, AccountType::Paikari, AccountType::Wholesaler] as $type) {
            $this->actingAs(User::factory()->create(['account_type' => $type]))->get('/terms')->assertOk();
        }
    }
}
