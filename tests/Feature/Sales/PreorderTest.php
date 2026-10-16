<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\Notice;
use App\Models\Preorder;
use App\Models\Role;
use App\Models\Setting;
use App\Models\StockSource;
use App\Models\Tier;
use App\Models\User;
use App\Services\Sales\PreOrders;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 21: pre-order of a sold-out product, answered by the seller, a draft memo that becomes the real order on receipt. */
class PreorderTest extends TestCase
{
    use RefreshDatabase;

    private User $shop;

    private User $staff;

    private Company $company;

    private Medicine $napa;

    private Medicine $seclo;

    protected function setUp(): void
    {
        parent::setUp();
        $tier = Tier::create(['name' => 'A', 'discount_percent' => 10]);
        $this->shop = User::factory()->create(['account_type' => AccountType::Paikari, 'tier_id' => $tier->id, 'name' => 'Karim Pharmacy']);
        $this->staff = User::factory()->create(['account_type' => AccountType::Staff]);
        $this->company = Company::create(['name' => 'Beacon']);
        $make = fn (string $name) => Medicine::create([
            'company_id' => $this->company->id, 'brand_name' => $name, 'strength' => '500 mg', 'form' => 'tablet',
            'packaging_type' => 'box', 'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true,
        ]);
        $this->napa = $make('Napa');
        $this->seclo = $make('Seclo');
        $stock = app(StockService::class);
        $stock->receive(StockSource::create(['name' => 'Direct', 'type' => 'company', 'default_percent' => 20]), [
            'invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $this->napa->id, 'units' => 10]],
        ]);
        $stock->setMasterSync(true);
    }

    private function on(?int $days = 3): void
    {
        $this->company->forceFill(['preorder_days' => $days])->save();
    }

    private function ask(User $by, ?Medicine $m = null, string $unit = 'box', float $qty = 5)
    {
        return $this->actingAs($by)->post('/preorders', ['medicine_id' => ($m ?? $this->seclo)->id, 'unit' => $unit, 'quantity' => $qty]);
    }

    private function accepted(?User $by = null, ?Medicine $m = null, float $qty = 5): Preorder
    {
        $this->on();
        $this->ask($by ?? $this->shop, $m, 'box', $qty);
        $pre = Preorder::latest('id')->firstOrFail();
        $this->actingAs($this->staff)->post("/admin/preorders/{$pre->id}/accept", ['days' => 3])->assertSessionHasNoErrors();

        return $pre->fresh();
    }

    public function test_pre_order_is_off_until_it_is_turned_on_and_a_medicine_setting_beats_its_company(): void
    {
        $svc = app(PreOrders::class);
        $this->assertNull($svc->daysFor($this->seclo));

        $this->on(4);
        $this->assertSame(4, $svc->daysFor($this->seclo->fresh('company')));

        $this->seclo->forceFill(['preorder_days' => 2])->save();
        $this->assertSame(2, $svc->daysFor($this->seclo->fresh('company')));

        $this->seclo->forceFill(['preorder_days' => 0])->save();
        $this->assertNull($svc->daysFor($this->seclo->fresh('company')));
    }

    public function test_a_shop_asks_for_a_pre_order_and_it_waits_for_an_answer(): void
    {
        $this->on(3);

        $this->ask($this->shop)->assertSessionHasNoErrors()->assertRedirect();

        $pre = Preorder::firstOrFail();
        $this->assertSame('requested', $pre->status);
        $this->assertSame(3, $pre->promised_days);
        $this->assertSame($this->shop->id, $pre->customer_id);
        $this->assertSame(1, $pre->lines()->count());
        $this->assertSame(0, Notice::count());   // nothing is said to anyone yet
    }

    public function test_it_is_refused_where_it_is_off_for_a_wrong_unit_and_for_a_second_open_one(): void
    {
        $this->ask($this->shop)->assertSessionHasErrors('preorder');

        $this->on();
        $this->ask($this->shop, $this->seclo, 'box', 0)->assertSessionHasErrors('quantity');
        $this->ask($this->shop)->assertSessionHasNoErrors();
        $this->ask($this->shop)->assertSessionHasErrors('preorder');
        $this->assertSame(1, Preorder::count());
    }

    public function test_only_customers_can_ask_and_a_visitor_is_sent_to_log_in(): void
    {
        $this->on();
        $this->post('/preorders', ['medicine_id' => $this->seclo->id, 'unit' => 'box', 'quantity' => 1])->assertRedirect('/login');
        $this->ask($this->staff)->assertRedirect('/');
        $this->ask(User::factory()->create(['account_type' => AccountType::Admin]))->assertRedirect('/');
        $this->ask(User::factory()->create(['account_type' => AccountType::Wholesaler]))->assertSessionHasNoErrors();
        $this->ask(User::factory()->create(['account_type' => AccountType::Public]))->assertSessionHasNoErrors();
        $this->assertSame(2, Preorder::count());
    }

    public function test_an_advance_is_a_share_of_what_the_buyer_would_pay(): void
    {
        $this->on();
        Setting::write('preorder.advance_percent', 20);

        $this->ask($this->shop, $this->seclo, 'box', 5);

        // 5 boxes at 300 less the shop's 10% = 1350; a fifth of that.
        $pre = Preorder::firstOrFail();
        $this->assertSame('270.00', (string) $pre->advance_amount);
        $this->assertSame('promised', $pre->advance_state);
    }

    public function test_the_seller_accepts_with_a_time_and_the_buyer_is_told(): void
    {
        $this->on();
        $this->ask($this->shop);
        $pre = Preorder::firstOrFail();

        $this->actingAs($this->staff)->post("/admin/preorders/{$pre->id}/accept", ['days' => 6])->assertSessionHasErrors('days');
        $this->post("/admin/preorders/{$pre->id}/accept", ['days' => 4])->assertSessionHasNoErrors();

        $pre->refresh();
        $this->assertSame('accepted', $pre->status);
        $this->assertSame(now()->addDays(4)->toDateString(), $pre->due_on->toDateString());
        $notice = Notice::where('user_id', $this->shop->id)->firstOrFail();
        $this->assertSame('preorder_accepted', $notice->kind);
        $this->assertSame(4, json_decode($notice->body, true)['days']);

        $this->post("/admin/preorders/{$pre->id}/accept")->assertSessionHasErrors('preorder');
    }

    public function test_declining_needs_a_reason_the_buyer_reads(): void
    {
        $this->on();
        $this->ask($this->shop);
        $pre = Preorder::firstOrFail();

        $this->actingAs($this->staff)->post("/admin/preorders/{$pre->id}/decline", [])->assertSessionHasErrors('note');
        $this->post("/admin/preorders/{$pre->id}/decline", ['note' => 'The company stopped this.'])->assertSessionHasNoErrors();

        $this->assertSame('declined', $pre->fresh()->status);
        $this->assertSame('preorder_declined', Notice::firstOrFail()->kind);
        $this->actingAs($this->shop)->get("/preorders/{$pre->id}")->assertInertia(fn (Assert $p) => $p->where('preorder.status', 'declined')->where('preorder.reply_note', 'The company stopped this.'));
    }

    public function test_the_draft_memo_can_be_edited_while_it_is_open_and_the_change_is_logged(): void
    {
        $pre = $this->accepted();
        $line = $pre->lines()->firstOrFail();

        $this->put("/admin/preorders/lines/{$line->id}", ['quantity' => 8])->assertSessionHasNoErrors();
        $this->assertSame('8.00', (string) $line->fresh()->quantity);
        $this->assertSame(5, ActivityLog::where('action', 'preorder.line_edited')->firstOrFail()->changes['before']);

        $this->put("/admin/preorders/lines/{$line->id}", ['quantity' => 0])->assertSessionHasErrors('quantity');

        $this->post("/admin/preorders/{$pre->id}/cancel", ['note' => 'x']);
        $this->put("/admin/preorders/lines/{$line->id}", ['quantity' => 3])->assertSessionHasErrors('preorder');
    }

    public function test_receiving_turns_the_draft_into_the_real_order_for_what_came_and_takes_the_stock(): void
    {
        $this->stockUp($this->seclo, 6);
        $pre = $this->accepted(null, $this->seclo, 10);
        $line = $pre->lines()->firstOrFail();
        $before = app(StockService::class)->onHand($this->seclo);

        $this->post("/admin/preorders/{$pre->id}/receive", ['received' => [$line->id => 6]])->assertSessionHasNoErrors();

        $pre->refresh();
        $order = Invoice::findOrFail($pre->invoice_id);
        $this->assertSame('fulfilled', $pre->status);
        $this->assertSame('paikari', $order->kind);
        $this->assertSame('verified', $order->status);
        $this->assertSame($this->shop->id, $order->customer_id);
        $this->assertSame('6.00', (string) $order->lines()->firstOrFail()->quantity);
        $this->assertEqualsWithDelta($before - 6, app(StockService::class)->onHand($this->seclo), 0.001);
        $this->assertSame('6.00', (string) $line->fresh()->received_quantity);

        // The buyer is told for the first time now, and that only some came.
        $told = Notice::where('kind', 'preorder_arrived')->firstOrFail();
        $this->assertTrue(json_decode($told->body, true)['short']);
        $this->assertNotNull($pre->told_at);
    }

    public function test_receive_is_refused_for_nothing_for_more_than_ordered_and_without_the_stock_and_nothing_is_left_half_done(): void
    {
        $pre = $this->accepted(null, $this->seclo, 5);
        $line = $pre->lines()->firstOrFail();

        $this->post("/admin/preorders/{$pre->id}/receive", ['received' => [$line->id => 0]])->assertSessionHasErrors('preorder');
        $this->post("/admin/preorders/{$pre->id}/receive", ['received' => [$line->id => 9]])->assertSessionHasErrors('preorder');
        // No Seclo has been received into stock yet, so it cannot be handed over.
        $this->post("/admin/preorders/{$pre->id}/receive", ['received' => [$line->id => 5]])->assertSessionHasErrors('preorder');

        $this->assertSame('accepted', $pre->fresh()->status);
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, Notice::where('kind', 'preorder_arrived')->count());
    }

    public function test_a_plain_account_gets_a_retail_order_with_a_private_link(): void
    {
        $this->stockUp($this->seclo, 5);
        $user = User::factory()->create(['account_type' => AccountType::Public, 'name' => 'Rina', 'phone' => '01722222222']);
        $pre = $this->accepted($user, $this->seclo, 2);

        $this->post("/admin/preorders/{$pre->id}/receive", ['received' => [$pre->lines()->firstOrFail()->id => 2]])->assertSessionHasNoErrors();

        $order = Invoice::firstOrFail();
        $this->assertSame('retail', $order->kind);
        $this->assertSame(40, strlen($order->public_token));
        $this->actingAs($user)->get("/preorders/{$pre->id}")->assertInertia(fn (Assert $p) => $p->where('preorder.status', 'fulfilled')->where('preorder.order.token', $order->public_token));
    }

    public function test_a_pre_order_past_its_time_turns_red_and_is_counted_for_the_seller(): void
    {
        $pre = $this->accepted();
        $this->assertSame(0, app(PreOrders::class)->attention());

        $pre->update(['due_on' => today()->subDay()]);

        $this->assertTrue($pre->fresh()->isLate());
        $this->assertSame(1, app(PreOrders::class)->attention());
        $this->actingAs($this->staff)->get('/admin/preorders')->assertInertia(fn (Assert $p) => $p->where('accepted.0.late', true)->where('auth.preorders', 1));
        $this->actingAs($this->shop)->get("/preorders/{$pre->id}")->assertInertia(fn (Assert $p) => $p->where('preorder.late', true));
    }

    public function test_the_buyer_cancelling_late_gets_the_advance_back_but_early_it_is_the_sellers_call(): void
    {
        Setting::write('preorder.advance_percent', 10);
        $early = $this->accepted(null, $this->seclo, 5);
        $this->post("/admin/preorders/{$early->id}/advance", ['state' => 'held']);
        $this->actingAs($this->shop)->post("/preorders/{$early->id}/cancel")->assertSessionHasNoErrors();
        $this->assertSame('held', $early->fresh()->advance_state);
        $this->assertSame('buyer', $early->fresh()->cancelled_by);

        $this->actingAs($this->staff)->post("/admin/preorders/{$early->id}/advance", ['state' => 'kept'])->assertSessionHasNoErrors();
        $this->assertSame('kept', $early->fresh()->advance_state);

        $late = $this->accepted(null, $this->napa, 3);
        $this->post("/admin/preorders/{$late->id}/advance", ['state' => 'held']);
        $late->update(['due_on' => today()->subDays(2)]);
        $this->actingAs($this->shop)->post("/preorders/{$late->id}/cancel");
        $this->assertSame('refund_due', $late->fresh()->advance_state);

        $this->actingAs($this->staff)->post("/admin/preorders/{$late->id}/advance", ['state' => 'refunded'])->assertSessionHasNoErrors();
        $this->assertSame('refunded', $late->fresh()->advance_state);
    }

    public function test_goods_not_taken_give_half_the_advance_back(): void
    {
        Setting::write('preorder.advance_percent', 10);
        $pre = $this->accepted(null, $this->seclo, 5);
        $this->post("/admin/preorders/{$pre->id}/advance", ['state' => 'held']);
        $this->actingAs($this->shop)->post("/preorders/{$pre->id}/cancel");
        $amount = (float) $pre->fresh()->advance_amount;

        $this->actingAs($this->staff)->post("/admin/preorders/{$pre->id}/advance", ['state' => 'half'])->assertSessionHasNoErrors();

        $this->assertSame('refunded', $pre->fresh()->advance_state);
        $this->assertEqualsWithDelta($amount / 2, (float) $pre->fresh()->advance_refunded, 0.011);
        $this->post("/admin/preorders/{$pre->id}/advance", ['state' => 'half'])->assertSessionHasErrors();   // only once
    }

    public function test_goods_that_never_came_end_with_a_sorry_note_and_the_advance_goes_back(): void
    {
        Setting::write('preorder.advance_percent', 10);
        $pre = $this->accepted();
        $this->post("/admin/preorders/{$pre->id}/advance", ['state' => 'held']);

        $this->post("/admin/preorders/{$pre->id}/cancel", [])->assertSessionHasErrors('note');
        $this->post("/admin/preorders/{$pre->id}/cancel", ['note' => 'The company did not supply it.'])->assertSessionHasNoErrors();

        $pre->refresh();
        $this->assertSame('cancelled', $pre->status);
        $this->assertSame('seller', $pre->cancelled_by);
        $this->assertSame('refund_due', $pre->advance_state);
        $sorry = Notice::where('kind', 'preorder_cancelled')->firstOrFail();
        $this->assertSame('The company did not supply it.', json_decode($sorry->body, true)['note']);
    }

    public function test_a_new_time_can_be_given_and_the_buyer_is_told(): void
    {
        $pre = $this->accepted();
        $pre->update(['due_on' => today()->subDay()]);

        $this->post("/admin/preorders/{$pre->id}/extend", ['days' => 5])->assertSessionHasNoErrors();

        $pre->refresh();
        $this->assertFalse($pre->isLate());
        $this->assertSame(1, $pre->extended);
        $this->assertSame(now()->addDays(5)->toDateString(), $pre->due_on->toDateString());
        $this->assertSame(1, Notice::where('kind', 'preorder_extended')->count());
    }

    public function test_the_buying_list_groups_by_company_in_the_order_asked_and_leaves_out_closed_ones(): void
    {
        $other = Company::create(['name' => 'Square']);
        $ace = Medicine::create(['company_id' => $other->id, 'brand_name' => 'Ace', 'form' => 'tablet', 'packaging_type' => 'box', 'mrp_box' => 100, 'is_active' => true]);
        $other->forceFill(['preorder_days' => 3])->save();
        $rahim = User::factory()->create(['account_type' => AccountType::Paikari, 'name' => 'Rahim Medico']);

        $this->on();
        $this->ask($this->shop, $this->seclo, 'box', 5);
        $this->ask($rahim, $this->seclo, 'box', 3);
        $this->ask($this->shop, $ace, 'box', 2);
        $this->ask($rahim, $this->napa, 'box', 1);
        Preorder::where('customer_id', $rahim->id)->whereHas('lines', fn ($q) => $q->where('medicine_id', $this->napa->id))->firstOrFail()->update(['status' => 'declined']);

        $list = app(PreOrders::class)->buyingList()->all();

        $this->assertSame(['Beacon', 'Square'], array_column($list, 'company'));
        $beacon = $list[0]['medicines'];
        $this->assertCount(1, $beacon);   // Napa was declined, so only Seclo
        $this->assertSame(['Karim Pharmacy', 'Rahim Medico'], array_column($beacon[0]['entries'], 'who'));
        $this->assertSame([5.0, 3.0], array_column($beacon[0]['entries'], 'quantity'));
    }

    public function test_the_seller_turns_it_on_for_a_company_or_a_medicine_and_off_again(): void
    {
        $this->actingAs($this->staff);

        $this->post('/admin/preorders/availability', ['type' => 'company', 'id' => $this->company->id, 'days' => 3])->assertSessionHasNoErrors();
        $this->assertSame(3, $this->company->fresh()->preorder_days);
        $this->post('/admin/preorders/availability', ['type' => 'medicine', 'id' => $this->napa->id, 'days' => 0]);
        $this->assertSame(0, $this->napa->fresh()->preorder_days);
        $this->post('/admin/preorders/availability', ['type' => 'company', 'id' => $this->company->id, 'days' => null]);
        $this->assertNull($this->company->fresh()->preorder_days);
        $this->post('/admin/preorders/availability', ['type' => 'company', 'id' => $this->company->id, 'days' => 9])->assertSessionHasErrors('days');
        $this->assertSame(3, ActivityLog::where('action', 'preorder.availability')->count());

        $this->get('/admin/preorders/companies?q=Bea')->assertJsonPath('results.0.name', 'Beacon');
    }

    public function test_the_shop_search_and_the_public_medicine_page_show_where_it_can_be_pre_ordered(): void
    {
        $this->on(4);

        $this->actingAs($this->shop)->getJson('/paikari/search?q=Seclo')->assertJsonPath('results.0.preorder_days', 4);
        $this->get('/medicines/'.$this->seclo->id)->assertInertia(fn (Assert $p) => $p->where('preorderDays', 4));
        $this->company->forceFill(['preorder_days' => null])->save();
        $this->get('/medicines/'.$this->seclo->id)->assertInertia(fn (Assert $p) => $p->where('preorderDays', null));
    }

    public function test_the_buyers_pages_are_private_and_the_seller_pages_need_the_orders_permission(): void
    {
        $this->on();
        $this->ask($this->shop);
        $pre = Preorder::firstOrFail();
        $other = User::factory()->create(['account_type' => AccountType::Paikari]);

        $this->actingAs($other)->get("/preorders/{$pre->id}")->assertRedirect('/');
        $this->post("/preorders/{$pre->id}/cancel")->assertRedirect('/');
        $this->actingAs($this->shop)->get('/preorders')->assertOk()->assertInertia(fn (Assert $p) => $p->has('items', 1));
        $this->actingAs($other)->get('/preorders')->assertInertia(fn (Assert $p) => $p->has('items', 0));

        $this->actingAs($this->shop)->get('/admin/preorders')->assertRedirect('/');
        $this->post("/admin/preorders/{$pre->id}/accept")->assertRedirect('/');
        $this->assertSame('requested', $pre->fresh()->status);

        $noOrders = User::factory()->create(['account_type' => AccountType::Staff, 'role_id' => Role::create(['name' => 'Stock only', 'permissions' => ['stock']])->id]);
        $this->actingAs($noOrders)->get('/admin/preorders')->assertRedirect('/');
    }

    public function test_a_notice_can_only_be_closed_by_its_owner(): void
    {
        $notice = Notice::create(['user_id' => $this->shop->id, 'kind' => 'preorder_accepted', 'body' => '{}']);
        $other = User::factory()->create(['account_type' => AccountType::Public]);

        $this->actingAs($other)->post("/notices/{$notice->id}/read")->assertRedirect('/');
        $this->assertNull($notice->fresh()->read_at);
        $this->actingAs($this->shop)->post("/notices/{$notice->id}/read");
        $this->assertNotNull($notice->fresh()->read_at);
    }

    private function stockUp(Medicine $m, float $units): void
    {
        app(StockService::class)->receive(StockSource::firstOrFail(), ['invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $m->id, 'units' => $units]]]);
    }
}
