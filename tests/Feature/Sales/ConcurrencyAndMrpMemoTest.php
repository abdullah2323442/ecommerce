<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\OfferProduct;
use App\Models\StockSource;
use App\Models\Tier;
use App\Models\User;
use App\Services\Sales\Presence;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 7: several staff on one order (who is working, first click wins) and the preliminary memo at printed prices. */
class ConcurrencyAndMrpMemoTest extends TestCase
{
    use RefreshDatabase;

    private User $rina;

    private User $robiul;

    private User $shop;

    private Medicine $napa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rina = User::factory()->create(['account_type' => AccountType::Staff, 'name' => 'Rina']);
        $this->robiul = User::factory()->create(['account_type' => AccountType::Staff, 'name' => 'Robiul']);
        $this->shop = User::factory()->create(['account_type' => AccountType::Paikari, 'tier_id' => Tier::create(['name' => 'A', 'discount_percent' => 10])->id]);
        $this->napa = Medicine::create([
            'company_id' => Company::create(['name' => 'Square'])->id, 'brand_name' => 'Napa', 'strength' => '500 mg', 'form' => 'tablet',
            'packaging_type' => 'box', 'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true,
        ]);
        $stock = app(StockService::class);
        $stock->receive(StockSource::create(['name' => 'Direct', 'type' => 'company', 'default_percent' => 20]), [
            'invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $this->napa->id, 'units' => 20]],
        ]);
        $stock->setMasterSync(true);
    }

    private function order(): Invoice
    {
        $this->actingAs($this->shop)->post('/paikari/cart', ['medicine_id' => $this->napa->id, 'unit' => 'box', 'quantity' => 4]);
        $this->post('/paikari/order', ['delivery_mode' => 'collect'])->assertSessionHasNoErrors();

        return Invoice::latest('id')->firstOrFail();
    }

    public function test_an_open_order_shows_who_else_is_working_on_it(): void
    {
        $order = $this->order();

        $this->actingAs($this->rina)->get("/admin/orders/{$order->id}")->assertInertia(fn (Assert $p) => $p->where('order.working', null));
        $this->postJson("/admin/orders/{$order->id}/working")->assertOk()->assertJson(['other' => null]);

        $this->actingAs($this->robiul)->get("/admin/orders/{$order->id}")->assertInertia(fn (Assert $p) => $p->where('order.working', 'Rina'));
        $this->postJson("/admin/orders/{$order->id}/working")->assertJson(['other' => 'Rina']);

        // And now Rina sees Robiul, but never herself.
        $this->actingAs($this->rina)->postJson("/admin/orders/{$order->id}/working")->assertJson(['other' => 'Robiul']);
    }

    public function test_a_heartbeat_that_has_gone_quiet_no_longer_counts(): void
    {
        $order = $this->order();
        app(Presence::class)->touch($order, $this->rina);

        $this->assertSame('Rina', app(Presence::class)->otherOn($order->fresh(), $this->robiul));

        $order->update(['working_at' => now()->subSeconds(Presence::FRESH_SECONDS + 5)]);
        $this->assertNull(app(Presence::class)->otherOn($order->fresh(), $this->robiul));
    }

    public function test_others_can_still_work_on_it_the_banner_does_not_lock_anything(): void
    {
        $order = $this->order();
        app(Presence::class)->touch($order, $this->rina);
        $line = $order->lines()->firstOrFail();

        $this->actingAs($this->robiul)->post("/admin/orders/{$order->id}/lines/{$line->id}/verify", ['status' => 'full'])->assertSessionHasNoErrors();
        $this->assertSame('full', $line->fresh()->verify_status);
    }

    public function test_the_presence_check_is_for_staff_who_handle_orders(): void
    {
        $order = $this->order();

        $this->actingAs($this->shop)->postJson("/admin/orders/{$order->id}/working")->assertRedirect('/');
        $this->assertNull($order->fresh()->working_by);
    }

    public function test_the_first_click_wins_and_the_second_is_told_who_got_there_first(): void
    {
        $order = $this->order();
        $line = $order->lines()->firstOrFail();

        // Both screens were drawn while the line was still pending.
        $this->actingAs($this->rina)->post("/admin/orders/{$order->id}/lines/{$line->id}/verify", ['status' => 'full', 'expected' => 'pending'])->assertSessionHasNoErrors();
        $this->actingAs($this->robiul)->post("/admin/orders/{$order->id}/lines/{$line->id}/verify", ['status' => 'unavailable', 'expected' => 'pending'])->assertSessionHasErrors('order');

        $line->refresh();
        $this->assertSame('full', $line->verify_status);
        $this->assertSame($this->rina->id, $line->checked_by);
        $this->assertStringContainsString('Rina', session('errors')->first('order'));
    }

    public function test_a_screen_that_is_up_to_date_can_change_the_line_and_the_name_follows(): void
    {
        $order = $this->order();
        $line = $order->lines()->firstOrFail();
        $this->actingAs($this->rina)->post("/admin/orders/{$order->id}/lines/{$line->id}/verify", ['status' => 'full', 'expected' => 'pending']);

        $this->actingAs($this->robiul)->post("/admin/orders/{$order->id}/lines/{$line->id}/verify", ['status' => 'partial', 'quantity' => 2, 'expected' => 'full'])->assertSessionHasNoErrors();

        $this->assertSame('partial', $line->fresh()->verify_status);
        $this->assertSame($this->robiul->id, $line->fresh()->checked_by);
        $this->actingAs($this->rina)->get("/admin/orders/{$order->id}")->assertInertia(fn (Assert $p) => $p->where('order.lines.0.checked_by', 'Robiul'));
    }

    public function test_without_an_expected_state_the_old_behaviour_is_kept(): void
    {
        $order = $this->order();
        $line = $order->lines()->firstOrFail();
        $this->actingAs($this->rina)->post("/admin/orders/{$order->id}/lines/{$line->id}/verify", ['status' => 'full']);

        $this->actingAs($this->robiul)->post("/admin/orders/{$order->id}/lines/{$line->id}/verify", ['status' => 'unavailable'])->assertSessionHasNoErrors();
        $this->assertSame('unavailable', $line->fresh()->verify_status);
    }

    public function test_a_shop_opens_a_preliminary_memo_at_the_printed_prices_while_its_order_is_open(): void
    {
        $order = $this->order();   // 4 boxes: the shop's own price is 270, the printed price 300

        $this->get("/memo/{$order->id}?mrp=1")->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('memo')->where('memo.preliminary', true)->where('memo.regular.lines.0.unit_price', 300)->where('memo.regular.lines.0.total', 1200)
            ->where('memo.regular.subtotal', 1200)->where('memo.total', 1200)->where('memo.delivery', 0));

        $this->get("/memo/{$order->id}")->assertInertia(fn (Assert $p) => $p->where('memo.preliminary', false)->where('memo.regular.lines.0.unit_price', 270));
    }

    public function test_the_preliminary_memo_leaves_offer_items_at_the_offer_rate(): void
    {
        $order = $this->order();
        $order->lines()->update(['offer_product_id' => null]);
        $offer = OfferProduct::create([
            'medicine_id' => $this->napa->id, 'percent' => 30, 'lot_percent' => 30, 'min_boxes' => 1, 'step_percent' => 0, 'visible_to' => ['paikari'], 'stock_mode' => 'all', 'ends_on' => now()->addDays(5),
        ]);
        $order->lines()->update(['offer_product_id' => $offer->id, 'unit_price' => 210]);

        $this->get("/memo/{$order->id}?mrp=1")->assertInertia(fn (Assert $p) => $p->where('memo.offer.lines.0.unit_price', 210)->where('memo.total', 840));
    }

    public function test_a_finished_memo_is_never_shown_at_printed_prices(): void
    {
        $order = $this->order();
        $order->update(['status' => 'final', 'number' => 'M2026-000001', 'total' => 1080, 'finalised_at' => now()]);

        $this->get("/memo/{$order->id}?mrp=1")->assertInertia(fn (Assert $p) => $p->where('memo.preliminary', false)->where('memo.regular.lines.0.unit_price', 270));
    }

    public function test_the_preliminary_memo_belongs_to_the_buyer_and_staff_only(): void
    {
        $order = $this->order();
        $other = User::factory()->create(['account_type' => AccountType::Paikari]);

        $this->actingAs($other)->get("/memo/{$order->id}?mrp=1")->assertRedirect('/');
        $this->actingAs($this->rina)->get("/memo/{$order->id}?mrp=1")->assertOk();
    }
}
