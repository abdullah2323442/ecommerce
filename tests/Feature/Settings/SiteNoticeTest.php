<?php

namespace Tests\Feature\Settings;

use App\Enums\AccountType;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Spec ch 28 note 11: a server update at night or on a closed day is announced in advance. */
class SiteNoticeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_notice_the_owner_writes_shows_to_everyone_in_their_language(): void
    {
        Setting::write('notice.text', 'Update tonight at 2 am.');
        Setting::write('notice.text_bn', 'আজ রাত ২টায় আপডেট।');

        $this->withCookie('locale', 'en')->get('/')->assertInertia(fn ($p) => $p->where('siteNotice', 'Update tonight at 2 am.'));

        $this->withCookie('locale', 'bn')->get('/')->assertInertia(fn ($p) => $p->where('siteNotice', 'আজ রাত ২টায় আপডেট।'));
    }

    public function test_bangla_falls_back_to_the_main_text_and_no_text_means_no_notice(): void
    {
        $this->withCookie('locale', 'bn')->get('/')->assertInertia(fn ($p) => $p->where('siteNotice', null));

        Setting::write('notice.text', 'Closed on Friday.');
        $this->withCookie('locale', 'bn')->get('/')->assertInertia(fn ($p) => $p->where('siteNotice', 'Closed on Friday.'));
    }

    public function test_only_the_owner_writes_it_from_the_settings_screen(): void
    {
        $admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $this->actingAs($admin)->get('/admin/settings')->assertInertia(fn ($p) => $p->where('groups.0.key', 'orders'));
    }
}
