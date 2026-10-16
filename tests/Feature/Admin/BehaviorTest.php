<?php

namespace Tests\Feature\Admin;

use App\Enums\AccountType;
use App\Http\Middleware\SetLocale;
use App\Models\Company;
use App\Models\Medicine;
use App\Models\User;
use App\Services\Analytics\Behavior;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 27: store behaviour from the first day; no names kept, staff left out, and a report to learn from it. */
class BehaviorTest extends TestCase
{
    use RefreshDatabase;

    private Medicine $napa;

    private Medicine $seclo;

    protected function setUp(): void
    {
        parent::setUp();
        $company = Company::create(['name' => 'Square']);
        $make = fn (string $name) => Medicine::create([
            'company_id' => $company->id, 'brand_name' => $name, 'strength' => '500 mg', 'form' => 'tablet', 'packaging_type' => 'box',
            'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true,
        ]);
        $this->napa = $make('Napa');
        $this->seclo = $make('Seclo');
    }

    private function events(?string $type = null)
    {
        return DB::table('behavior_events')->when($type, fn ($q) => $q->where('type', $type))->orderBy('id')->get();
    }

    public function test_a_search_is_recorded_with_what_was_typed_and_how_many_were_found(): void
    {
        $this->get('/medicines?q=Napa')->assertOk();
        $this->get('/medicines?q=Zzzzqq')->assertOk();

        $rows = $this->events('search');
        $this->assertCount(2, $rows);
        $this->assertSame('Napa', $rows[0]->term);
        $this->assertGreaterThanOrEqual(1, json_decode($rows[0]->meta, true)['found']);
        $this->assertSame(0, json_decode($rows[1]->meta, true)['found']);
    }

    public function test_an_empty_search_and_a_second_page_are_not_counted_again(): void
    {
        $this->get('/medicines')->assertOk();
        $this->get('/medicines?q=Napa&page=2')->assertOk();

        $this->assertCount(0, $this->events('search'));
    }

    public function test_a_medicine_look_counts_once_per_visitor_per_half_hour(): void
    {
        $this->get("/medicines/{$this->napa->id}");
        $this->get("/medicines/{$this->napa->id}");
        $this->get("/medicines/{$this->seclo->id}");

        $views = $this->events('view');
        $this->assertCount(2, $views);
        $this->assertSame([$this->napa->id, $this->seclo->id], $views->pluck('subject_id')->map(fn ($v) => (int) $v)->all());
    }

    public function test_the_visitor_is_a_hash_and_a_signed_in_person_is_known_by_id_only(): void
    {
        $this->get("/medicines/{$this->napa->id}");
        $guest = $this->events('view')->first();
        $this->assertNull($guest->user_id);
        $this->assertSame(40, strlen($guest->visitor));
        $this->assertSame(0, preg_match('/[^a-f0-9]/', $guest->visitor));

        $user = User::factory()->create(['account_type' => AccountType::Public]);
        $this->actingAs($user)->get("/medicines/{$this->seclo->id}");
        $this->assertSame($user->id, (int) $this->events('view')->last()->user_id);
        $this->assertStringNotContainsString($user->email, json_encode($this->events()));
    }

    public function test_adding_to_the_cart_and_sending_an_order_and_a_pre_order_are_recorded(): void
    {
        $this->post('/cart', ['medicine_id' => $this->napa->id, 'unit' => 'strip', 'quantity' => 2]);
        $this->post('/checkout', ['name' => 'Hasan', 'phone' => '01711000000', 'district' => 'Dhaka', 'area' => 'Mirpur', 'address' => 'House 5', 'delivery_mode' => 'delivery']);

        $this->assertSame((int) $this->napa->id, (int) $this->events('cart')->first()->subject_id);
        $this->assertSame(1, json_decode($this->events('checkout')->first()->meta, true)['lines']);

        $this->seclo->company->forceFill(['preorder_days' => 3])->save();
        $user = User::factory()->create(['account_type' => AccountType::Public]);
        $this->actingAs($user)->post('/preorders', ['medicine_id' => $this->seclo->id, 'unit' => 'box', 'quantity' => 1]);
        $this->assertSame((int) $this->seclo->id, (int) $this->events('preorder')->first()->subject_id);
    }

    public function test_staff_and_the_owner_are_left_out_so_the_numbers_are_about_customers(): void
    {
        foreach ([AccountType::Staff, AccountType::Admin] as $type) {
            $this->actingAs(User::factory()->create(['account_type' => $type]))->get("/medicines/{$this->napa->id}");
        }

        $this->assertCount(0, $this->events());
    }

    public function test_a_record_that_fails_never_breaks_the_page(): void
    {
        DB::statement('drop table behavior_events');

        $this->get("/medicines/{$this->napa->id}")->assertOk();
        app(Behavior::class)->record('not-a-type');
    }

    public function test_the_report_counts_searches_looks_and_carts_and_flags_what_found_nothing(): void
    {
        $this->get('/medicines?q=Napa');
        $this->get('/medicines?q=napa');
        $this->get('/medicines?q=Zzzzqq');
        $this->get("/medicines/{$this->napa->id}");
        $this->post('/cart', ['medicine_id' => $this->napa->id, 'unit' => 'strip', 'quantity' => 1]);
        $this->get("/medicines/{$this->seclo->id}");

        $admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $this->actingAs($admin)->get('/admin/behavior')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('admin/behavior')->where('days', 30)
            ->where('searches.0.term', 'napa')->where('searches.0.times', 2)
            ->where('searches.1.term', 'zzzzqq')->where('searches.1.found', 0)
            ->where('medicines.0.views', 1)->where('funnel.search', 1)->where('funnel.view', 1)->where('funnel.cart', 1));
    }

    public function test_the_report_can_look_at_a_shorter_period(): void
    {
        $this->get('/medicines?q=Napa');
        DB::table('behavior_events')->update(['created_at' => now()->subDays(20)]);
        $admin = User::factory()->create(['account_type' => AccountType::Admin]);

        $this->actingAs($admin)->get('/admin/behavior?days=7')->assertInertia(fn (Assert $p) => $p->where('days', 7)->has('searches', 0));
        $this->get('/admin/behavior?days=30')->assertInertia(fn (Assert $p) => $p->has('searches', 1));
        $this->get('/admin/behavior?days=5')->assertInertia(fn (Assert $p) => $p->where('days', 30));
    }

    public function test_only_those_who_see_reports_open_it(): void
    {
        $this->get('/admin/behavior')->assertRedirect();
        $this->actingAs(User::factory()->create(['account_type' => AccountType::Staff]))->get('/admin/behavior')->assertRedirect('/');
        $this->actingAs(User::factory()->create(['account_type' => AccountType::Paikari]))->get('/admin/behavior')->assertRedirect('/');
    }

    public function test_the_privacy_page_says_what_is_noted(): void
    {
        $this->withCookie(SetLocale::COOKIE, 'en')->get('/privacy')->assertInertia(fn (Assert $p) => $p
            ->where('sections.0.body', fn ($lines) => collect($lines)->contains(fn ($l) => str_contains($l, 'search for and look at'))));
    }
}
