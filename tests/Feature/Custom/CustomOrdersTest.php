<?php

namespace Tests\Feature\Custom;

use App\Enums\AccountType;
use App\Models\CustomMaterial;
use App\Models\CustomOrder;
use App\Models\MeasurementProfile;
use App\Models\Notice;
use App\Models\Setting;
use App\Models\User;
use App\Services\Custom\Craftsmen;
use App\Services\Custom\CustomOrders;
use App\Services\Custom\MeasurementProfiles;
use App\Services\Market\MarketOrders;
use App\Services\Stock\StockException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 16: made-to-order wood and clothing, measurement profiles, our memo, advance, cancelling, and "work not done right". */
class CustomOrdersTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;

    private User $staff;

    private User $maker;

    private CustomMaterial $teak;

    private CustomMaterial $silk;

    private CustomOrders $orders;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 09:00:00');
        Storage::fake();
        $this->buyer = User::factory()->create(['account_type' => AccountType::Public, 'name' => 'Karim']);
        $this->staff = User::factory()->create(['account_type' => AccountType::Staff]);
        $this->maker = User::factory()->create(['account_type' => AccountType::Craftsman, 'name' => 'Karim Mistri']);
        $this->teak = CustomMaterial::create(['kind' => 'wood', 'name' => 'Teak', 'name_bn' => 'সেগুন', 'rate' => 3000]);
        CustomMaterial::create(['kind' => 'wood', 'name' => 'Gamari', 'rate' => 1500]);
        $this->silk = CustomMaterial::create(['kind' => 'fabric', 'name' => 'Silk', 'rate' => 800]);
        $this->orders = app(CustomOrders::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function wood(array $over = []): CustomOrder
    {
        return $this->orders->place($this->buyer, array_merge([
            'kind' => 'wood', 'material_id' => $this->teak->id, 'items' => [['values' => ['length' => 72, 'width' => 36, 'height' => 30], 'unit' => 'inch']], 'details' => ['polish' => 'Walnut', 'drawers' => '3', 'glass' => true, 'junk' => 'x'],
        ], $over));
    }

    private function clothing(?array $items = null): CustomOrder
    {
        return $this->orders->place($this->buyer, [
            'kind' => 'clothing', 'fabric_by' => 'shop', 'material_id' => $this->silk->id,
            'items' => $items ?? [['kind' => 'men', 'garment' => 'Panjabi', 'values' => ['chest' => 40, 'waist' => 34], 'unit' => 'inch']],
        ]);
    }

    private function memo(CustomOrder $o, array $over = []): CustomOrder
    {
        return $this->orders->price($o, $this->staff, array_merge(['material_cost' => 4000, 'wages_total' => 1500, 'base_price' => 9000, 'days_needed' => 10], $over));
    }

    private function ready(CustomOrder $o): CustomOrder
    {
        $o = $this->memo($o);
        $o = $this->orders->confirm($o, $this->buyer, true);
        $o = $this->orders->receiveAdvance($o, $this->staff, 2700);
        $o = app(Craftsmen::class)->assign($o, $this->staff, $this->maker);

        return $this->orders->start($o, $this->staff);
    }

    /** The work is all done and entered at 100%, so it can be marked ready. */
    private function finish(CustomOrder $o): CustomOrder
    {
        app(Craftsmen::class)->record($o->fresh(), $this->maker, $this->maker, 100, UploadedFile::fake()->image('work.jpg'));

        return $this->orders->markDone($o->fresh(), $this->staff);
    }

    // ------------------------------------------------------------------ ordering

    public function test_a_wood_order_keeps_the_wood_its_rate_and_the_measurements_and_drops_unknown_options(): void
    {
        $o = $this->wood(['note' => 'For the hall', 'urgent' => true]);

        $this->assertSame(['requested', 'Teak', '3000.00'], [$o->status, $o->material_name, (string) $o->material_rate]);
        $this->assertEquals(72, $o->items[0]['values']['length']);
        $this->assertSame(['polish' => 'Walnut', 'drawers' => '3', 'glass' => true], $o->details);
        $this->assertSame(1, Notice::where('user_id', $this->staff->id)->where('kind', 'custom')->count());
        $this->assertSame('placed', $o->events()->first()->kind);
    }

    public function test_the_wood_rate_is_kept_as_it_was_when_ordered(): void
    {
        $o = $this->wood();
        $this->teak->update(['rate' => 5000]);

        $this->assertSame('3000.00', (string) $o->fresh()->material_rate);
    }

    public function test_a_wood_order_needs_a_wood_and_a_measurement(): void
    {
        foreach ([['material_id' => null], ['items' => [['values' => [], 'unit' => 'inch']]], ['items' => [['values' => ['length' => -3], 'unit' => 'inch']]], ['items' => [['values' => ['length' => 5], 'unit' => 'yard']]]] as $bad) {
            try {
                $this->wood($bad);
                $this->fail('should be refused');
            } catch (StockException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_clothing_takes_several_people_and_needs_to_know_who_brings_the_fabric(): void
    {
        $o = $this->clothing([
            ['kind' => 'men', 'garment' => 'Panjabi', 'values' => ['chest' => 40], 'unit' => 'inch', 'label' => 'Rahim'],
            ['kind' => 'women', 'garment' => 'Salwar kameez', 'values' => ['bust' => 34, 'hip' => 38], 'unit' => 'inch', 'quantity' => 2],
        ]);

        $this->assertCount(2, $o->items);
        $this->assertSame(2, $o->items[1]['quantity']);

        $this->expectException(StockException::class);
        $this->orders->place($this->buyer, ['kind' => 'clothing', 'items' => [['kind' => 'men', 'garment' => 'Panjabi', 'values' => ['chest' => 40]]]]);
    }

    public function test_when_the_buyer_brings_the_fabric_no_fabric_is_chosen(): void
    {
        $o = $this->orders->place($this->buyer, ['kind' => 'clothing', 'fabric_by' => 'buyer', 'items' => [['kind' => 'men', 'garment' => 'Panjabi', 'values' => ['chest' => 40]]]]);

        $this->assertSame(['buyer', null], [$o->fabric_by, $o->material_id]);
    }

    public function test_a_saved_profile_is_copied_into_the_order_with_its_date_and_later_edits_do_not_change_the_job(): void
    {
        $profiles = app(MeasurementProfiles::class);
        $mine = $profiles->save($this->buyer, 'Mine', 'men', 'inch', ['chest' => 40, 'waist' => 34]);
        $o = $this->clothing([['profile_id' => $mine->id, 'garment' => 'Shirt']]);

        $profiles->save($this->buyer, 'Mine', 'men', 'inch', ['chest' => 44], $mine);

        $this->assertEquals([40, 34], [$o->fresh()->items[0]['values']['chest'], $o->fresh()->items[0]['values']['waist']]);
        $this->assertSame(['Mine', '2026-10-15'], [$o->items[0]['label'], $o->items[0]['measured_on']]);
        $this->assertSame([$o->id], $profiles->orders($mine->fresh())->pluck('id')->all());   // the list of previous orders
    }

    public function test_someone_elses_profile_cannot_be_used(): void
    {
        $other = User::factory()->create();
        $theirs = app(MeasurementProfiles::class)->save($other, 'Theirs', 'men', 'inch', ['chest' => 40]);

        $o = $this->clothing([['profile_id' => $theirs->id, 'kind' => 'men', 'garment' => 'Shirt', 'values' => ['chest' => 38]]]);

        $this->assertNull($o->items[0]['profile_id']);
        $this->assertEquals(38, $o->items[0]['values']['chest']);
    }

    // ------------------------------------------------------------------ profiles

    public function test_profiles_have_unique_names_a_date_and_an_edit(): void
    {
        $profiles = app(MeasurementProfiles::class);
        $p = $profiles->save($this->buyer, "My son's", 'men', 'inch', ['chest' => 30]);

        $this->assertSame('2026-10-15', $p->measured_on->toDateString());
        $this->expectException(StockException::class);
        $profiles->save($this->buyer, "My son's", 'men', 'inch', ['chest' => 31]);
    }

    public function test_old_measurements_ask_if_they_are_still_right(): void
    {
        $profiles = app(MeasurementProfiles::class);
        $p = $profiles->save($this->buyer, 'Mine', 'men', 'inch', ['chest' => 40]);
        $this->assertFalse($profiles->isStale($p));

        Carbon::setTestNow('2027-06-15 09:00:00');   // eight months on, the limit is six
        $this->assertTrue($profiles->isStale($p->fresh()));
        $this->assertSame(8, $profiles->present($p->fresh())['months']);

        $profiles->stillCorrect($p->fresh());
        $this->assertFalse($profiles->isStale($p->fresh()));
    }

    public function test_a_profile_only_keeps_numbers_for_its_own_fields(): void
    {
        $this->expectException(StockException::class);
        app(MeasurementProfiles::class)->save($this->buyer, 'Bad', 'women', 'inch', ['chest' => 40]);   // a woman's list has bust, not chest
    }

    // ------------------------------------------------------------------ our memo

    public function test_we_make_the_memo_and_the_buyer_sees_a_total_but_never_the_costs(): void
    {
        $o = $this->memo($this->wood(['urgent' => true]), ['advance_percent' => 40, 'memo_note' => 'Includes the polish']);

        $this->assertEquals([9000, 1800, 10800, 40], [$o->base_price, $o->urgent_fee, $o->price, $o->advance_percent]);   // urgent adds 20%
        $view = json_encode($this->orders->forBuyer($o->load('events')));
        $this->assertStringNotContainsString('4000', $view);   // material cost
        $this->assertStringNotContainsString('1500', $view);   // wages
        $this->assertSame(1, Notice::where('user_id', $this->buyer->id)->where('kind', 'custom_priced')->count());
    }

    public function test_the_advance_defaults_to_the_setting(): void
    {
        Setting::write('custom.advance_percent', 50);

        $this->assertSame(50, $this->memo($this->wood())->advance_percent);
    }

    public function test_the_memo_needs_sane_numbers(): void
    {
        $o = $this->wood();

        foreach ([['base_price' => 0], ['material_cost' => -1], ['advance_percent' => 101], ['days_needed' => 0]] as $bad) {
            try {
                $this->memo($o, $bad);
                $this->fail('should be refused');
            } catch (StockException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_we_can_change_the_price_until_the_buyer_confirms(): void
    {
        $o = $this->memo($this->wood());
        $o = $this->memo($o, ['base_price' => 8000]);
        $this->assertEquals(8000, $o->price);

        $this->orders->confirm($o, $this->buyer, true);
        $this->expectException(StockException::class);
        $this->memo($o->fresh(), ['base_price' => 5000]);
    }

    // ------------------------------------------------------------------ both sides confirm

    public function test_the_buyer_must_say_they_checked_the_measurements(): void
    {
        $o = $this->memo($this->wood());

        try {
            $this->orders->confirm($o, $this->buyer, false);
            $this->fail('should be refused');
        } catch (StockException) {
        }

        $this->assertSame('confirmed', $this->orders->confirm($o, $this->buyer, true)->status);
        $this->assertSame(['placed', 'priced', 'confirmed'], $o->events()->pluck('kind')->all());   // the record of who gave what and when
    }

    public function test_only_the_buyer_can_confirm_their_own_job(): void
    {
        $o = $this->memo($this->wood());

        $this->expectException(StockException::class);
        $this->orders->confirm($o, User::factory()->create(), true);
    }

    // ------------------------------------------------------------------ making it

    public function test_work_needs_the_advance_and_the_wages(): void
    {
        $o = $this->orders->confirm($this->memo($this->wood()), $this->buyer, true);
        $this->assertEquals(2700, $o->advanceDue());   // 30% of 9000

        try {
            $this->orders->start($o, $this->staff);
            $this->fail('the advance is not in');
        } catch (StockException) {
        }

        $this->orders->receiveAdvance($o, $this->staff, 1000);
        $this->assertEquals(1700, $o->fresh()->advanceDue());

        $this->orders->receiveAdvance($o->fresh(), $this->staff, 1700);

        try {
            $this->orders->start($o->fresh(), $this->staff);
            $this->fail('nobody has the job');
        } catch (StockException) {
        }

        app(Craftsmen::class)->assign($o->fresh(), $this->staff, $this->maker);
        $started = $this->orders->start($o->fresh(), $this->staff);

        $this->assertSame(['making', '2026-10-25'], [$started->status, $started->due_on->toDateString()]);
        $this->assertSame(1, Notice::where('user_id', $this->buyer->id)->where('kind', 'custom_started')->count());
    }

    public function test_clothing_has_a_trial_step_and_wood_does_not(): void
    {
        $c = $this->ready($this->clothing());
        $this->assertSame('trial', $this->orders->callTrial($c, $this->staff)->status);
        $this->assertSame(1, Notice::where('user_id', $this->buyer->id)->where('kind', 'custom_trial')->count());
        $this->assertSame('making', $this->orders->endTrial($c->fresh(), $this->staff)->status);

        $w = $this->ready($this->wood());
        $this->expectException(StockException::class);
        $this->orders->callTrial($w, $this->staff);
    }

    public function test_done_collected_and_the_pickup_reminder(): void
    {
        $o = $this->finish($this->ready($this->wood()));
        $this->assertSame(1, Notice::where('user_id', $this->buyer->id)->where('kind', 'custom_done')->count());
        $this->assertSame(0, $this->orders->remindPickup());

        Carbon::setTestNow('2026-10-23 10:00:00');   // eight days on, the wait is seven
        $this->assertSame(1, $this->orders->remindPickup());
        $this->assertSame(0, $this->orders->remindPickup());   // not again the same day

        Carbon::setTestNow('2026-10-31 10:00:00');
        $this->assertSame(1, $this->orders->remindPickup());

        $this->assertSame('collected', $this->orders->collect($o->fresh(), $this->staff)->status);
        $this->assertSame(0, $this->orders->remindPickup());
    }

    // ------------------------------------------------------------------ cancelling

    public function test_cancelled_before_work_the_advance_comes_back_and_after_it_does_not(): void
    {
        $early = $this->orders->confirm($this->memo($this->wood()), $this->buyer, true);
        $this->orders->receiveAdvance($early, $this->staff, 2700);
        $this->assertEquals(2700, $this->orders->cancel($early->fresh(), $this->buyer, 'buyer', 'Changed my mind')->refund_amount);

        $late = $this->ready($this->wood());
        $this->assertEquals(0, $this->orders->cancel($late, $this->buyer, 'buyer')->refund_amount);
    }

    public function test_if_we_cancel_the_advance_always_comes_back(): void
    {
        $late = $this->ready($this->wood());

        $this->assertEquals(2700, $this->orders->cancel($late, $this->staff, 'staff', 'We cannot get the wood')->refund_amount);
        $this->assertSame(1, Notice::where('user_id', $this->buyer->id)->where('kind', 'custom_cancelled')->count());
    }

    public function test_finished_work_cannot_be_cancelled(): void
    {
        $o = $this->finish($this->ready($this->wood()));

        $this->expectException(StockException::class);
        $this->orders->cancel($o, $this->buyer, 'buyer');
    }

    // ------------------------------------------------------------------ work not done right

    public function test_work_not_done_right_is_raised_and_answered(): void
    {
        $o = $this->finish($this->ready($this->wood()));

        $this->orders->complain($o, $this->buyer, 'The drawer sticks');
        $this->assertSame('open', $o->fresh()->complaint_status);

        $done = $this->orders->resolveComplaint($o->fresh(), $this->staff, 'refunded', 'Our mistake', 1500);
        $this->assertEquals(['refunded', 1500], [$done->complaint_status, $done->refund_amount]);
        $this->assertSame(1, Notice::where('user_id', $this->buyer->id)->where('kind', 'custom_answered')->count());
    }

    public function test_a_complaint_needs_finished_work_and_cannot_be_raised_twice(): void
    {
        $o = $this->ready($this->wood());

        try {
            $this->orders->complain($o, $this->buyer, 'Too early');
            $this->fail('not finished');
        } catch (StockException) {
        }

        $o = $this->finish($o);
        $this->orders->complain($o, $this->buyer, 'The drawer sticks');
        $this->expectException(StockException::class);
        $this->orders->complain($o->fresh(), $this->buyer, 'Again');
    }

    public function test_the_buyers_wrong_measurements_are_the_buyers_responsibility(): void
    {
        $o = $this->finish($this->ready($this->wood()));
        $this->orders->complain($o, $this->buyer, 'Too short');

        $done = $this->orders->resolveComplaint($o->fresh(), $this->staff, 'buyer', 'It was made to the measurements you confirmed');

        $this->assertEquals(['buyer', 0], [$done->complaint_status, $done->refund_amount]);
    }

    // ------------------------------------------------------------------ no commission, no return

    public function test_there_is_no_commission_and_no_part_in_the_market_accounts(): void
    {
        $this->finish($this->ready($this->wood()));

        $this->assertSame(0, app(MarketOrders::class)->owed()->count());
        $this->assertFalse(\Schema::hasColumn('custom_orders', 'commission'));
    }

    // ------------------------------------------------------------------ pages

    public function test_the_buyer_orders_from_the_page_and_follows_it(): void
    {
        $this->actingAs($this->buyer)->get('/custom')->assertInertia(fn (Assert $p) => $p->component('custom/index'));
        $this->actingAs($this->buyer)->get('/custom/new/wood')->assertInertia(fn (Assert $p) => $p->component('custom/new')->has('materials', 2)->where('materials.0.rate', 1500)->has('fields.wood'));

        $this->post('/custom', ['kind' => 'wood', 'material_id' => $this->teak->id, 'items' => [['values' => ['length' => 72], 'unit' => 'inch']], 'photo' => UploadedFile::fake()->image('room.jpg')])->assertSessionHasNoErrors();
        $order = CustomOrder::firstOrFail();
        $this->assertNotNull($order->photo_path);

        $this->get("/custom/{$order->public_token}")->assertInertia(fn (Assert $p) => $p->component('custom/show')->where('order.status', 'requested')->where('order.has_photo', true));
        $this->get("/custom/{$order->public_token}/photo")->assertOk();
    }

    public function test_another_buyer_cannot_see_the_job_or_its_photo(): void
    {
        $order = $this->wood();
        $other = User::factory()->create(['account_type' => AccountType::Paikari]);

        $this->actingAs($other)->get("/custom/{$order->public_token}")->assertRedirect('/');
        $this->actingAs($other)->get("/custom/{$order->public_token}/photo")->assertRedirect('/');
        $this->actingAs($other)->post("/custom/{$order->public_token}/cancel")->assertRedirect('/');
        $this->assertSame('requested', $order->fresh()->status);
    }

    public function test_a_guest_signs_in_first_and_staff_and_sellers_have_no_buyer_screens(): void
    {
        $this->get('/custom')->assertRedirect();
        $this->actingAs($this->staff)->get('/custom')->assertRedirect('/');
        $this->actingAs(User::factory()->create(['account_type' => AccountType::Craftsman]))->get('/custom')->assertRedirect('/');
    }

    public function test_we_work_the_job_from_the_admin_page(): void
    {
        $order = $this->wood();

        $this->actingAs($this->staff)->get('/admin/custom')->assertInertia(fn (Assert $p) => $p->component('admin/custom-orders')->has('asking', 1)->where('asking.0.buyer', 'Karim'));
        $this->put("/admin/custom/{$order->id}/price", ['material_cost' => 4000, 'wages_total' => 1500, 'base_price' => 9000, 'days_needed' => 10])->assertSessionHasNoErrors();

        $this->actingAs($this->buyer)->post("/custom/{$order->public_token}/confirm", ['checked' => true])->assertSessionHasNoErrors();

        $this->actingAs($this->staff)->post("/admin/custom/{$order->id}/advance", ['amount' => 2700])->assertSessionHasNoErrors();
        $this->post("/admin/custom/{$order->id}/assign", ['craftsman_id' => $this->maker->id])->assertSessionHasNoErrors();
        $this->post("/admin/custom/{$order->id}/start")->assertSessionHasNoErrors();
        $this->post("/admin/custom/{$order->id}/done")->assertSessionHasErrors('custom');   // below 100% it is not complete
        app(Craftsmen::class)->record($order->fresh(), $this->maker, $this->maker, 100, UploadedFile::fake()->image('w.jpg'));
        $this->post("/admin/custom/{$order->id}/done")->assertSessionHasNoErrors();
        $this->get('/admin/custom')->assertInertia(fn (Assert $p) => $p->has('done', 1)->where('done.0.profit', 3500));   // price 9000 − material 4000 − wages 1500
        $this->post("/admin/custom/{$order->id}/bogus")->assertRedirect('/');
    }

    public function test_the_wood_used_comes_off_the_stock_when_work_starts(): void
    {
        $this->teak->update(['stock' => 10]);
        $order = $this->wood();
        $this->actingAs($this->staff)->put("/admin/custom/{$order->id}/price", ['material_cost' => 4000, 'material_qty' => 6, 'wages_total' => 1500, 'base_price' => 9000, 'days_needed' => 10])->assertSessionHasNoErrors();
        $this->actingAs($this->buyer)->post("/custom/{$order->public_token}/confirm", ['checked' => true]);
        $this->actingAs($this->staff)->post("/admin/custom/{$order->id}/advance", ['amount' => 2700]);
        $this->post("/admin/custom/{$order->id}/assign", ['craftsman_id' => $this->maker->id]);

        $this->assertSame('10.00', (string) $this->teak->fresh()->stock, 'nothing is used while it is only a price');

        $this->post("/admin/custom/{$order->id}/start")->assertSessionHasNoErrors();
        $this->assertSame('4.00', (string) $this->teak->fresh()->stock);
        $this->assertNotNull($order->fresh()->material_used_at);
    }

    public function test_work_does_not_start_when_there_is_not_enough_wood_and_an_uncounted_wood_is_left_alone(): void
    {
        $this->teak->update(['stock' => 2]);
        $order = $this->wood();
        $this->actingAs($this->staff)->put("/admin/custom/{$order->id}/price", ['material_cost' => 4000, 'material_qty' => 6, 'wages_total' => 1500, 'base_price' => 9000, 'days_needed' => 10]);
        $this->actingAs($this->buyer)->post("/custom/{$order->public_token}/confirm", ['checked' => true]);
        $this->actingAs($this->staff)->post("/admin/custom/{$order->id}/advance", ['amount' => 2700]);
        $this->post("/admin/custom/{$order->id}/assign", ['craftsman_id' => $this->maker->id]);

        $this->post("/admin/custom/{$order->id}/start")->assertSessionHasErrors('custom');
        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertSame('2.00', (string) $this->teak->fresh()->stock);

        $this->teak->update(['stock' => null]);   // the owner does not count this wood
        $this->post("/admin/custom/{$order->id}/start")->assertSessionHasNoErrors();
        $this->assertNull($this->teak->fresh()->stock);
        $this->assertSame('making', $order->fresh()->status);
    }

    public function test_the_buyer_view_never_carries_the_costs(): void
    {
        $order = $this->memo($this->wood());

        $json = '';
        $this->actingAs($this->buyer)->get("/custom/{$order->public_token}")->assertInertia(function (Assert $p) use (&$json) {
            $json = json_encode($p->toArray()['props']['order']);
        });

        $this->assertStringNotContainsString('material_cost', $json);
        $this->assertStringNotContainsString('wages', $json);
        $this->assertStringNotContainsString('profit', $json);
        $this->assertStringNotContainsString('4000', $json);
    }

    public function test_only_the_owner_sets_the_rates_of_woods_and_fabrics(): void
    {
        $this->actingAs($this->staff)->post('/admin/custom/materials', ['kind' => 'wood', 'name' => 'Mahogany', 'rate' => 2500, 'is_active' => true])->assertRedirect('/');
        $this->assertSame(0, CustomMaterial::where('name', 'Mahogany')->count());

        $admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $this->actingAs($admin)->post('/admin/custom/materials', ['kind' => 'wood', 'name' => 'Mahogany', 'rate' => 2500, 'is_active' => true])->assertSessionHasNoErrors();
        $this->actingAs($admin)->put("/admin/custom/materials/{$this->teak->id}", ['kind' => 'wood', 'name' => 'Teak', 'rate' => 3300, 'is_active' => false])->assertSessionHasNoErrors();

        $this->assertSame(['3300.00', false], [(string) $this->teak->fresh()->rate, $this->teak->fresh()->is_active]);
        $this->actingAs($this->buyer)->get('/custom/new/wood')->assertInertia(fn (Assert $p) => $p->has('materials', 2));   // the switched-off teak is not offered
    }

    public function test_profile_pages(): void
    {
        $this->actingAs($this->buyer)->post('/custom/profiles', ['name' => 'Mine', 'kind' => 'men', 'unit' => 'inch', 'values' => ['chest' => 40]])->assertSessionHasNoErrors();
        $p = MeasurementProfile::firstOrFail();

        $this->get('/custom/profiles')->assertInertia(fn (Assert $page) => $page->component('custom/profiles')->has('profiles', 1)->where('profiles.0.name', 'Mine'));
        $this->put("/custom/profiles/{$p->id}", ['name' => 'Mine', 'kind' => 'men', 'unit' => 'inch', 'values' => ['chest' => 41]])->assertSessionHasNoErrors();
        $this->assertEquals(41, $p->fresh()->values['chest']);

        $other = User::factory()->create();
        $this->actingAs($other)->put("/custom/profiles/{$p->id}/still-correct")->assertRedirect('/');
        $this->actingAs($other)->delete("/custom/profiles/{$p->id}")->assertRedirect('/');
        $this->assertSame(1, MeasurementProfile::count());

        $this->actingAs($this->buyer)->delete("/custom/profiles/{$p->id}")->assertSessionHasNoErrors();
        $this->assertSame(0, MeasurementProfile::count());
    }
}
