<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\LoginEvent;
use App\Models\Medicine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Never read the real backup disk: a real backup on this machine would hide the "no backup" alert.
        Storage::fake('local');
    }

    public function test_guests_are_redirected_to_the_login_page()
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_a_non_admin_gets_the_plain_account_page_with_no_admin_data()
    {
        $user = User::factory()->create(['account_type' => AccountType::Paikari]);

        $this->actingAs($user)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('account')
            // Only this viewer's own label, made on the server; the raw type list never reaches the browser.
            ->has('account.type_label')
            ->missing('account.type')
            ->missing('stats')
            ->missing('alerts'));
    }

    public function test_the_admin_gets_the_dashboard_with_stats_and_alerts()
    {
        $admin = User::factory()->create(['account_type' => AccountType::Admin]);
        User::factory()->count(2)->create(['account_type' => AccountType::Paikari]);
        $company = Company::create(['name' => 'Square']);
        Medicine::create(['company_id' => $company->id, 'brand_name' => 'Napa', 'packaging_type' => 'box', 'is_active' => true, 'image_source_url' => 'https://example.test/a.jpg']);
        Medicine::create(['company_id' => $company->id, 'brand_name' => 'Ace', 'packaging_type' => 'box', 'is_active' => true, 'image_path' => 'medicine_images/a.webp']);

        $this->actingAs($admin)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('stats.customers.total', 2)
            ->where('stats.customers.without_tier', 2)
            ->where('stats.medicines.total', 2)
            ->where('stats.medicines.local_image', 1)
            ->where('stats.medicines.remote_image', 1)
            ->where('stats.medicines.flagged', 0)
            ->has('alerts'));
    }

    public function test_alerts_say_when_there_is_no_backup_and_it_is_stored_locally()
    {
        $admin = User::factory()->create(['account_type' => AccountType::Admin]);
        config(['backup.disk' => 'local']);

        $titles = collect($this->actingAs($admin)->get('/dashboard')->viewData('page')['props']['alerts'])->pluck('title');

        $this->assertTrue($titles->contains('No backup has been made yet'));
        $this->assertTrue($titles->contains('Backups are stored on this server'));
    }

    public function test_suspicious_logins_raise_a_warning()
    {
        $admin = User::factory()->create(['account_type' => AccountType::Admin]);
        LoginEvent::create(['user_id' => $admin->id, 'ip' => '127.0.0.1', 'outcome' => 'success', 'is_suspicious' => true, 'created_at' => now()]);

        $titles = collect($this->actingAs($admin)->get('/dashboard')->viewData('page')['props']['alerts'])->pluck('title');

        $this->assertTrue($titles->contains('{n} suspicious login in the last 7 days'));
    }

    public function test_a_fresh_backup_clears_the_missing_and_stale_backup_alerts()
    {
        $admin = User::factory()->create(['account_type' => AccountType::Admin]);
        config(['backup.disk' => 'local', 'backup.directory' => 'backups']);
        Storage::disk('local')->put('backups/db-2026-10-10_020000.sql.gz', 'x');

        $titles = collect($this->actingAs($admin)->get('/dashboard')->viewData('page')['props']['alerts'])->pluck('title');

        $this->assertFalse($titles->contains('No backup has been made yet'));
        $this->assertFalse($titles->contains('The last backup is more than 36 hours old'));
    }

    public function test_home_is_the_public_home_page()
    {
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->component('home'));
    }
}
