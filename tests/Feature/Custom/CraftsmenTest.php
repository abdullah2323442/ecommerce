<?php

namespace Tests\Feature\Custom;

use App\Enums\AccountType;
use App\Models\CraftsmanPayment;
use App\Models\CustomMaterial;
use App\Models\CustomOrder;
use App\Models\CustomProgress;
use App\Models\Notice;
use App\Models\User;
use App\Services\Custom\Craftsmen;
use App\Services\Custom\CustomOrders;
use App\Services\Stock\StockException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 16.4 and 16.7: craftsmen, one per job, shift and share, wages by percentage with a photo, owed and paid. */
class CraftsmenTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;

    private User $staff;

    private User $admin;

    private User $karim;

    private User $rahim;

    private User $selim;

    private Craftsmen $crafts;

    private CustomOrders $orders;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        Carbon::setTestNow('2026-10-15 09:00:00');
        $this->buyer = User::factory()->create(['account_type' => AccountType::Public, 'name' => 'Buyer Bibi', 'phone' => '01712345678']);
        $this->staff = User::factory()->create(['account_type' => AccountType::Staff]);
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $this->karim = User::factory()->create(['account_type' => AccountType::Craftsman, 'name' => 'Karim']);
        $this->rahim = User::factory()->create(['account_type' => AccountType::Craftsman, 'name' => 'Rahim']);
        $this->selim = User::factory()->create(['account_type' => AccountType::Craftsman, 'name' => 'Selim']);
        $this->crafts = app(Craftsmen::class);
        $this->orders = app(CustomOrders::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function order(): CustomOrder
    {
        $teak = CustomMaterial::firstOrCreate(['name' => 'Teak'], ['kind' => 'wood', 'rate' => 3000]);
        $o = $this->orders->place($this->buyer, ['kind' => 'wood', 'material_id' => $teak->id, 'items' => [['values' => ['length' => 72, 'width' => 36], 'unit' => 'inch']]]);
        $o = $this->orders->price($o, $this->staff, ['material_cost' => 4000, 'wages_total' => 1000, 'base_price' => 9000, 'days_needed' => 10]);

        return $this->orders->confirm($o, $this->buyer, true);
    }

    /** Confirmed, advance in, given to Karim and started. */
    private function making(): CustomOrder
    {
        $o = $this->order();
        $this->orders->receiveAdvance($o, $this->staff, 2700);
        $o = $this->crafts->assign($o->fresh(), $this->staff, $this->karim);

        return $this->orders->start($o, $this->staff);
    }

    private function photo(): UploadedFile
    {
        return UploadedFile::fake()->image('work.jpg');
    }

    // ------------------------------------------------------------------ wages first

    public function test_a_job_without_agreed_wages_does_not_go_to_a_craftsman_at_all(): void
    {
        $o = $this->order();
        $o->update(['wages_total' => null]);

        $this->expectException(StockException::class);
        $this->crafts->assign($o->fresh(), $this->staff, $this->karim);
    }

    public function test_a_craftsman_is_asked_proposes_and_we_finalize_the_wage(): void
    {
        $o = $this->order();
        $this->crafts->askWage($o, $this->staff, $this->karim);
        $this->assertSame(1, Notice::where('user_id', $this->karim->id)->where('kind', 'craftsman_wage_ask')->count());

        $this->assertTrue($this->crafts->canSee($o->fresh(), $this->karim));
        $this->assertFalse($this->crafts->canSee($o->fresh(), $this->rahim));

        $this->crafts->propose($o->fresh(), $this->karim, 1200, 'Hard wood');
        $this->assertEquals(1200, $o->fresh()->wage_proposed);
        $this->assertSame(1, Notice::where('user_id', $this->staff->id)->where('kind', 'custom_proposal')->count());

        $this->crafts->setWages($o->fresh(), $this->staff, 1200);
        $this->assertEquals(1200, $o->fresh()->wages_total);
    }

    public function test_only_the_one_asked_can_propose(): void
    {
        $o = $this->order();
        $this->crafts->askWage($o, $this->staff, $this->karim);

        $this->expectException(StockException::class);
        $this->crafts->propose($o->fresh(), $this->rahim, 900);
    }

    // ------------------------------------------------------------------ one per job, shift, share

    public function test_the_job_has_one_main_craftsman_and_work_needs_one(): void
    {
        $o = $this->order();
        $this->orders->receiveAdvance($o, $this->staff, 2700);

        try {
            $this->orders->start($o->fresh(), $this->staff);
            $this->fail('nobody has the job');
        } catch (StockException) {
        }

        $o = $this->crafts->assign($o->fresh(), $this->staff, $this->karim);
        $this->assertSame($this->karim->id, $o->craftsman_id);
        $this->assertSame(1, Notice::where('user_id', $this->karim->id)->where('kind', 'craftsman_job')->count());
        $this->assertSame(1, $this->crafts->activeJobs($this->karim));
        $this->assertSame(0, $this->crafts->activeJobs($this->rahim));
    }

    public function test_only_a_craftsman_account_can_be_given_a_job(): void
    {
        $this->expectException(StockException::class);
        $this->crafts->assign($this->order(), $this->staff, $this->buyer);
    }

    public function test_shifting_moves_the_job_keeps_what_was_earned_and_stays_in_history(): void
    {
        $o = $this->making();
        $this->crafts->record($o, $this->karim, $this->karim, 20, $this->photo());

        $o = $this->crafts->shift($o->fresh(), $this->karim, $this->rahim, 'I am unwell');

        $this->assertSame($this->rahim->id, $o->craftsman_id);
        $this->assertFalse($this->crafts->canSee($o, $this->karim));   // Karim no longer sees the job
        $this->assertTrue($this->crafts->canSee($o, $this->rahim));
        $this->assertEquals(200, $this->crafts->wageOn($o, $this->karim));   // but he keeps the 20% he did
        $this->assertSame(['assign', 'shift'], $o->moves()->pluck('kind')->all());
        $this->assertSame(1, Notice::where('user_id', $this->staff->id)->where('kind', 'custom_shift')->count());

        // the new one continues from where it stands
        $this->crafts->record($o, $this->rahim, $this->rahim, 30, $this->photo());
        $this->assertEquals(50, $this->crafts->done($o));
    }

    public function test_only_the_main_craftsman_can_shift_or_share(): void
    {
        $o = $this->making();

        foreach ([fn () => $this->crafts->shift($o, $this->rahim, $this->selim), fn () => $this->crafts->share($o, $this->rahim, $this->selim)] as $try) {
            try {
                $try();
                $this->fail('not the main one');
            } catch (StockException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_sharing_takes_someone_on_and_they_see_the_job_but_only_their_own_wage(): void
    {
        $o = $this->making();
        $this->crafts->share($o, $this->karim, $this->rahim);
        $this->crafts->record($o, $this->karim, $this->karim, 20, $this->photo());
        $this->crafts->record($o, $this->karim, $this->rahim, 50, $this->photo());

        $this->assertTrue($this->crafts->canSee($o->fresh(), $this->rahim));
        $mine = $this->crafts->jobFor($o->fresh(), $this->rahim);
        $this->assertSame([false, null, 500.0], [$mine['is_main'], $mine['wage_total'], $mine['my_wage']]);   // 50% of 1,000
        $main = $this->crafts->jobFor($o->fresh(), $this->karim);
        $this->assertSame([true, 1000.0, 200.0], [$main['is_main'], $main['wage_total'], $main['my_wage']]);

        $this->expectException(StockException::class);
        $this->crafts->share($o->fresh(), $this->karim, $this->rahim);   // already on it
    }

    // ------------------------------------------------------------------ percentages

    public function test_wages_are_divided_by_the_percentages(): void
    {
        $o = $this->making();
        $this->crafts->share($o, $this->karim, $this->rahim);
        $this->crafts->share($o, $this->karim, $this->selim);
        $this->crafts->record($o, $this->karim, $this->karim, 20, $this->photo());
        $this->crafts->record($o, $this->karim, $this->rahim, 50, $this->photo());
        $this->crafts->record($o, $this->karim, $this->selim, 30, $this->photo());

        $o = $o->fresh();
        $this->assertEquals([200, 500, 300], [$this->crafts->wageOn($o, $this->karim), $this->crafts->wageOn($o, $this->rahim), $this->crafts->wageOn($o, $this->selim)]);   // the spec's example
        $this->assertEquals(100, $this->crafts->done($o));
    }

    public function test_a_photo_is_needed_with_every_update(): void
    {
        $o = $this->making();

        $this->expectException(StockException::class);
        $this->crafts->record($o, $this->karim, $this->karim, 40, null);
    }

    public function test_the_total_cannot_pass_a_hundred(): void
    {
        $o = $this->making();
        $this->crafts->record($o, $this->karim, $this->karim, 80, $this->photo());

        $this->expectException(StockException::class);
        $this->crafts->record($o, $this->karim, $this->karim, 30, $this->photo());
    }

    public function test_only_someone_on_the_job_can_be_credited(): void
    {
        $o = $this->making();

        $this->expectException(StockException::class);
        $this->crafts->record($o, $this->karim, $this->selim, 10, $this->photo());
    }

    public function test_below_a_hundred_it_is_not_complete_and_at_a_hundred_it_is(): void
    {
        $o = $this->making();
        $this->crafts->record($o, $this->karim, $this->karim, 70, $this->photo());

        try {
            $this->orders->markDone($o->fresh(), $this->staff);
            $this->fail('70% is not complete');
        } catch (StockException $e) {
            $this->assertStringContainsString('70', $e->getMessage());
        }

        $this->crafts->record($o, $this->karim, $this->karim, 30, $this->photo());
        $this->assertSame('done', $this->orders->markDone($o->fresh(), $this->staff)->status);
    }

    public function test_the_buyer_sees_how_far_and_never_who(): void
    {
        $o = $this->making();
        $this->crafts->share($o, $this->karim, $this->rahim);
        $this->crafts->record($o, $this->karim, $this->karim, 20, $this->photo());
        $this->crafts->record($o, $this->karim, $this->rahim, 50, $this->photo());

        $view = $this->orders->forBuyer($o->fresh()->load('events'));

        $this->assertSame(70, $view['progress']);
        $json = json_encode($view);
        foreach (['Karim', 'Rahim', 'craftsman'] as $name) {
            $this->assertStringNotContainsStringIgnoringCase($name, $json);
        }
    }

    public function test_we_can_fix_a_split_that_is_wrong(): void
    {
        $o = $this->making();
        $this->crafts->share($o, $this->karim, $this->rahim);
        $entry = $this->crafts->record($o, $this->karim, $this->karim, 60, $this->photo());

        $this->crafts->correct($entry, $this->staff, 40, $this->rahim);

        $this->assertEquals([0, 400], [$this->crafts->wageOn($o->fresh(), $this->karim), $this->crafts->wageOn($o->fresh(), $this->rahim)]);

        $this->expectException(StockException::class);
        $this->crafts->correct($entry->fresh(), $this->staff, 150);
    }

    // ------------------------------------------------------------------ materials, time

    public function test_materials_needed_shows_waiting_until_they_arrive(): void
    {
        $o = $this->making();

        $this->crafts->materialsNeeded($o, $this->karim, 'Out of teak');
        $this->assertTrue($this->orders->forBuyer($o->fresh()->load('events'))['waiting']);
        $this->assertSame(1, Notice::where('user_id', $this->staff->id)->where('kind', 'custom_waiting')->count());

        $this->crafts->materialsArrived($o->fresh(), $this->staff);
        $this->assertFalse($this->orders->forBuyer($o->fresh()->load('events'))['waiting']);
    }

    public function test_someone_not_on_the_job_cannot_say_materials_are_needed(): void
    {
        $this->expectException(StockException::class);
        $this->crafts->materialsNeeded($this->making(), $this->rahim, 'Out of teak');
    }

    public function test_a_late_job_tells_us_and_the_buyer_once_and_counts_the_delay(): void
    {
        $o = $this->making();
        $this->assertSame(['late' => 0, 'stalled' => 0], $this->crafts->watch());

        Carbon::setTestNow('2026-10-27 09:30:00');   // due on the 25th
        $first = $this->crafts->watch();
        $second = $this->crafts->watch();

        $this->assertSame([1, 0], [$first['late'], $second['late']]);
        $this->assertSame(1, Notice::where('user_id', $this->buyer->id)->where('kind', 'custom_late')->count());
        $this->assertTrue($this->orders->forBuyer($o->fresh()->load('events'))['late']);
        $this->assertSame(1, $this->crafts->ledger($this->karim)['delays']);

        $this->crafts->extend($o->fresh(), $this->staff, 5);
        $this->assertSame('2026-11-01', $o->fresh()->due_on->toDateString());
    }

    public function test_no_update_for_three_days_reminds_the_craftsman_and_tells_us(): void
    {
        $o = $this->making();
        $this->crafts->record($o, $this->karim, $this->karim, 10, $this->photo());

        Carbon::setTestNow('2026-10-17 09:30:00');
        $this->assertSame(0, $this->crafts->watch()['stalled']);

        Carbon::setTestNow('2026-10-19 09:30:00');   // four days on
        $this->assertSame(1, $this->crafts->watch()['stalled']);
        $this->assertSame(0, $this->crafts->watch()['stalled']);
        $this->assertSame(1, Notice::where('user_id', $this->karim->id)->where('kind', 'craftsman_reminder')->count());
        $this->assertSame(1, Notice::where('user_id', $this->staff->id)->where('kind', 'custom_stalled')->count());
    }

    // ------------------------------------------------------------------ money

    public function test_the_advance_goes_to_the_craftsman_but_never_more_than_we_received(): void
    {
        $o = $this->making();

        $this->crafts->giveAdvance($o, $this->admin, 2000);
        $this->assertEquals(2000, $o->fresh()->advance_given);

        $this->expectException(StockException::class);
        $this->crafts->giveAdvance($o->fresh(), $this->admin, 800);   // only 700 of the 2,700 is left
    }

    public function test_owed_and_paid_are_kept_apart_with_the_month_at_a_glance(): void
    {
        $o = $this->making();
        $this->crafts->record($o, $this->karim, $this->karim, 60, $this->photo());
        $this->crafts->giveAdvance($o, $this->admin, 300);
        $this->crafts->pay($this->karim, $this->admin, 100, 'Eid');

        $ledger = $this->crafts->ledger($this->karim);

        $this->assertEquals([600, 400, 200, 600], [$ledger['earned'], $ledger['paid'], $ledger['owed'], $ledger['month']]);
        $this->assertEquals(0, $this->crafts->ledger($this->karim, '2026-09')['month']);
        $this->assertSame(2, CraftsmanPayment::count());
    }

    public function test_a_cancelled_job_still_pays_for_work_done(): void
    {
        $o = $this->making();
        $this->crafts->record($o, $this->karim, $this->karim, 25, $this->photo());
        $this->orders->cancel($o->fresh(), $this->staff, 'staff', 'Buyer left');

        $this->assertEquals(250, $this->crafts->ledger($this->karim)['earned']);
    }

    // ------------------------------------------------------------------ the portal

    public function test_a_craftsman_sees_the_job_and_never_the_price_the_buyer_or_the_profit(): void
    {
        $o = $this->making();
        $json = '';

        $this->actingAs($this->karim)->get('/craftsman')->assertInertia(fn (Assert $p) => $p->component('craftsman/index')->has('jobs', 1)->where('jobs.0.number', $o->number())->has('ledger'));
        $this->get("/craftsman/jobs/{$o->id}")->assertInertia(function (Assert $p) use (&$json) {
            $p->component('craftsman/show');
            $props = $p->toArray()['props'];
            $json = json_encode([$props['job'], $props['others']]);
        });

        foreach (['9000', '4000', 'profit', 'Buyer Bibi', '01712345678', 'price', 'material_cost', 'stock'] as $secret) {
            $this->assertStringNotContainsStringIgnoringCase($secret, $json);
        }
        $this->assertStringContainsString('1000', $json);   // his own wage is there
    }

    public function test_another_craftsman_cannot_open_a_job_or_its_photos(): void
    {
        $o = $this->making();
        $entry = $this->crafts->record($o, $this->karim, $this->karim, 10, $this->photo());

        $this->actingAs($this->rahim)->get("/craftsman/jobs/{$o->id}")->assertRedirect('/');
        $this->actingAs($this->rahim)->get("/craftsman/progress/{$entry->id}/photo")->assertRedirect('/');
        $this->actingAs($this->rahim)->post("/craftsman/jobs/{$o->id}/progress", ['craftsman_id' => $this->rahim->id, 'percent' => 10, 'photo' => $this->photo()])->assertRedirect('/');
        $this->assertSame(1, CustomProgress::count());
        $this->actingAs($this->karim)->get("/craftsman/progress/{$entry->id}/photo")->assertOk();
    }

    public function test_the_main_craftsman_enters_progress_shifts_and_shares_from_the_page(): void
    {
        $o = $this->making();

        $this->actingAs($this->karim)->post("/craftsman/jobs/{$o->id}/progress", ['craftsman_id' => $this->karim->id, 'percent' => 40, 'photo' => $this->photo()])->assertSessionHasNoErrors();
        $this->post("/craftsman/jobs/{$o->id}/progress", ['craftsman_id' => $this->karim->id, 'percent' => 10])->assertSessionHasErrors('photo');
        $this->post("/craftsman/jobs/{$o->id}/waiting", ['note' => 'Need polish'])->assertSessionHasNoErrors();
        $this->post("/craftsman/jobs/{$o->id}/share", ['to' => $this->rahim->id])->assertSessionHasNoErrors();
        $this->post("/craftsman/jobs/{$o->id}/shift", ['to' => $this->selim->id])->assertSessionHasNoErrors();

        $this->assertSame($this->selim->id, $o->fresh()->craftsman_id);
        $this->actingAs($this->karim)->post("/craftsman/jobs/{$o->id}/shift", ['to' => $this->rahim->id])->assertRedirect('/');   // no longer his job
    }

    public function test_only_craftsmen_reach_the_portal(): void
    {
        $this->get('/craftsman')->assertRedirect();
        foreach ([$this->buyer, $this->staff] as $user) {
            $this->actingAs($user)->get('/craftsman')->assertRedirect('/');
        }
    }

    // ------------------------------------------------------------------ our side

    public function test_the_owner_opens_craftsman_accounts_and_pays_wages(): void
    {
        $this->actingAs($this->admin)->post('/admin/craftsmen', ['name' => 'Jalal', 'phone' => '01811111111', 'password' => 'long-password-1'])->assertSessionHasNoErrors();
        $jalal = User::where('name', 'Jalal')->firstOrFail();
        $this->assertSame(AccountType::Craftsman, $jalal->account_type);

        $this->post("/admin/craftsmen/{$jalal->id}/pay", ['amount' => 500, 'note' => 'Advance on wages'])->assertSessionHasNoErrors();
        $this->get('/admin/craftsmen')->assertInertia(fn (Assert $p) => $p->component('admin/craftsmen')->has('craftsmen', 4)->where('canOpen', true));

        $this->actingAs($this->staff)->post('/admin/craftsmen', ['name' => 'Nope', 'password' => 'long-password-1'])->assertRedirect('/');
        $this->assertSame(0, User::where('name', 'Nope')->count());
    }

    public function test_we_assign_ask_and_correct_from_the_admin_page(): void
    {
        $o = $this->order();
        $this->orders->receiveAdvance($o, $this->staff, 2700);

        $this->actingAs($this->staff)->post("/admin/custom/{$o->id}/wage-ask", ['craftsman_id' => $this->rahim->id])->assertSessionHasNoErrors();
        $this->post("/admin/custom/{$o->id}/wages", ['amount' => 1200])->assertSessionHasNoErrors();
        $this->post("/admin/custom/{$o->id}/assign", ['craftsman_id' => $this->karim->id])->assertSessionHasNoErrors();
        $this->post("/admin/custom/{$o->id}/start")->assertSessionHasNoErrors();

        $this->get('/admin/custom')->assertInertia(fn (Assert $p) => $p->has('craftsmen', 3)->where('making.0.craftsman', 'Karim')->where('making.0.wages_total', 1200));
        $this->actingAs($this->admin)->post("/admin/custom/{$o->id}/give-advance", ['amount' => 1000])->assertSessionHasNoErrors();
        $this->actingAs($this->staff)->post("/admin/custom/{$o->id}/give-advance", ['amount' => 100])->assertRedirect('/');   // money out is the owner's

        $this->crafts->record($o->fresh(), $this->karim, $this->karim, 50, $this->photo());
        $entry = CustomProgress::firstOrFail();
        $this->actingAs($this->staff)->put("/admin/custom/progress/{$entry->id}", ['percent' => 45])->assertSessionHasNoErrors();
        $this->get("/admin/custom/progress/{$entry->id}/photo")->assertOk();
        $this->assertEquals(45, $entry->fresh()->percent);
    }
}
