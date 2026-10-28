<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\Notice;
use App\Models\SaleReturn;
use App\Models\Setting;
use App\Models\StockLot;
use App\Models\StockSource;
use App\Models\Tier;
use App\Models\User;
use App\Services\Messages\Messenger;
use App\Services\Sales\InvoiceService;
use App\Services\Sales\ReturnService;
use App\Services\Stock\StockException;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReturnsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $staff;

    private User $shop;

    private Medicine $napa;

    private StockService $stock;

    private InvoiceService $invoices;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $this->staff = User::factory()->create(['account_type' => AccountType::Staff]);
        $this->shop = User::factory()->create(['account_type' => AccountType::Paikari, 'tier_id' => Tier::create(['name' => 'B', 'discount_percent' => 10])->id]);
        // 3 strips of 10 pieces: box 300, strip 100, piece 10. The shop pays 270 a box, 90 a strip, 9 a piece.
        $this->napa = Medicine::create([
            'company_id' => Company::create(['name' => 'Square'])->id, 'brand_name' => 'Napa', 'strength' => '500 mg', 'form' => 'tablet',
            'packaging_type' => 'box', 'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true,
        ]);
        $this->stock = app(StockService::class);
        $this->invoices = app(InvoiceService::class);
        $this->stock->receive(StockSource::create(['name' => 'Direct', 'type' => 'company', 'default_percent' => 20]), [
            'invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $this->napa->id, 'units' => 10]],
        ]);
        $this->stock->setMasterSync(true);
    }

    /** A finished sale of boxes to the shop (or retail when $shop is false). */
    private function sale(int $boxes = 2, bool $shop = true): Invoice
    {
        $draft = $this->invoices->open($shop ? 'paikari' : 'retail', $this->staff, $shop ? $this->shop : null);
        $this->invoices->addLine($draft, $this->napa, 'box', $boxes, $this->staff);

        return $this->invoices->finalise($draft, $this->staff);
    }

    private function items(Invoice $inv, string $unit, float $qty): array
    {
        return [['line_id' => $inv->lines()->first()->id, 'unit' => $unit, 'quantity' => $qty]];
    }

    public function test_a_partial_return_is_valued_at_the_price_paid(): void
    {
        $inv = $this->sale(1);

        $r = app(ReturnService::class)->request($inv, $this->items($inv, 'piece', 20), 'damaged', 'Two strips damaged', 'credit', $this->staff);

        $this->assertSame('180.00', (string) $r->amount, '20 pieces at the 9 taka they paid');
        $this->assertSame('requested', $r->status);
    }

    public function test_nothing_moves_until_the_admin_approves(): void
    {
        $inv = $this->sale(2);
        $before = $this->stock->onHand($this->napa);
        app(ReturnService::class)->request($inv, $this->items($inv, 'box', 1), 'wrong_item', 'Wrong one', 'credit', $this->staff);

        $this->assertEqualsWithDelta($before, $this->stock->onHand($this->napa), 1e-6);
        $this->assertSame(0.0, app(ReturnService::class)->creditBalance($this->shop));
    }

    public function test_approving_puts_the_goods_back_into_their_own_lot_and_credits_the_shop(): void
    {
        $inv = $this->sale(2);
        $lot = StockLot::firstOrFail();
        $this->assertEqualsWithDelta(8.0, (float) $lot->fresh()->units_remaining, 1e-6);

        $r = app(ReturnService::class)->request($inv, $this->items($inv, 'box', 1), 'damaged', 'Damaged', 'credit', $this->staff);
        $this->actingAs($this->admin)->post("/admin/returns/{$r->id}/approve")->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(9.0, (float) $lot->fresh()->units_remaining, 1e-6, 'back into the same lot');
        $this->assertSame(270.0, app(ReturnService::class)->creditBalance($this->shop));
        $this->assertSame('approved', $r->fresh()->status);
    }

    public function test_a_lot_that_ran_out_reopens_with_just_the_returned_goods(): void
    {
        $inv = $this->sale(10);
        $lot = StockLot::firstOrFail();
        $this->assertEqualsWithDelta(0.0, (float) $lot->fresh()->units_remaining, 1e-6);

        $r = app(ReturnService::class)->request($inv, $this->items($inv, 'strip', 3), 'damaged', 'x', 'exchange', $this->staff);
        app(ReturnService::class)->approve($r, $this->admin);

        $this->assertEqualsWithDelta(1.0, (float) $lot->fresh()->units_remaining, 1e-6, '3 strips = 1 box, in the same lot at the same cost');
        $this->assertSame('240.0000', (string) $lot->fresh()->unit_cost);
    }

    public function test_the_return_shrinks_the_sale_so_profit_follows(): void
    {
        $inv = $this->sale(2);
        $line = $inv->lines()->first();
        $r = app(ReturnService::class)->request($inv, $this->items($inv, 'box', 1), 'damaged', 'x', 'credit', $this->staff);
        app(ReturnService::class)->approve($r, $this->admin);

        $this->assertEqualsWithDelta(1.0, abs((float) $line->movements()->where('type', 'sale')->where('reversed', false)->sum('units')), 1e-6);
    }

    public function test_cash_is_half_and_exchange_or_credit_is_full(): void
    {
        $inv = $this->sale(2);
        $svc = app(ReturnService::class);

        $cash = $svc->request($inv, $this->items($inv, 'box', 1), 'other', 'x', 'cash', $this->staff);
        $svc->approve($cash, $this->admin);
        $this->assertSame('135.00', (string) $cash->fresh()->cash_amount);
        $this->assertSame('0.00', (string) $cash->fresh()->credit_amount);

        $swap = $svc->request($inv, $this->items($inv, 'box', 1), 'other', 'x', 'exchange', $this->staff);
        $svc->approve($swap, $this->admin);
        $this->assertSame('270.00', (string) $swap->fresh()->credit_amount);
    }

    public function test_an_offer_return_in_cash_is_half_the_offer_rate(): void
    {
        $line = $this->sale(1)->lines()->first();
        $line->update(['offer_product_id' => null]); // keep the test free of an offer row; the rule is the price paid
        $inv = $this->sale(1);
        $inv->lines()->first()->update(['unit_price' => 300]); // the offer rate they paid

        $r = app(ReturnService::class)->request($inv, $this->items($inv, 'box', 1), 'other', 'x', 'cash', $this->staff);
        app(ReturnService::class)->approve($r, $this->admin);

        $this->assertSame('150.00', (string) $r->fresh()->cash_amount, 'offer rate 300 gives 150 in cash');
    }

    public function test_you_cannot_return_more_than_was_bought_and_not_yet_returned(): void
    {
        $inv = $this->sale(1);
        $svc = app(ReturnService::class);
        $svc->request($inv, $this->items($inv, 'strip', 2), 'other', 'x', 'credit', $this->staff);

        $this->expectExceptionMessage('That is more than was bought and not yet returned.');
        $svc->request($inv, $this->items($inv, 'strip', 2), 'other', 'x', 'credit', $this->staff);
    }

    public function test_a_declined_return_frees_the_quantity_again(): void
    {
        $inv = $this->sale(1);
        $svc = app(ReturnService::class);
        $r = $svc->request($inv, $this->items($inv, 'box', 1), 'other', 'x', 'credit', $this->staff);
        $svc->reject($r, $this->admin, 'not sealed');

        $this->assertTrue($svc->returnable($inv->lines()->first()->fresh()));
    }

    public function test_the_window_is_counted_from_receipt_and_the_days_are_a_setting(): void
    {
        Carbon::setTestNow('2026-10-12 10:00');
        $inv = $this->sale(1);
        $svc = app(ReturnService::class);

        Carbon::setTestNow('2026-10-14 10:00');
        $this->assertTrue($svc->returnable($inv->lines()->first()));

        Carbon::setTestNow('2026-10-16 10:00');
        $this->assertFalse($svc->returnable($inv->lines()->first()), 'four days later: no option at all');

        try {
            $svc->request($inv, $this->items($inv, 'box', 1), 'other', 'x', 'credit', $this->staff);
            $this->fail('staff cannot go past the window');
        } catch (StockException $e) {
            $this->assertSame('The return period for this memo is over.', $e->getMessage());
        }

        Setting::write('returns.window_days', 7);
        $this->assertTrue($svc->returnable($inv->lines()->first()));
        Carbon::setTestNow();
    }

    public function test_a_reason_is_mandatory(): void
    {
        $inv = $this->sale(1);
        $this->actingAs($this->staff)->post('/admin/returns', ['invoice_id' => $inv->id, 'items' => $this->items($inv, 'box', 1), 'reason_code' => 'other', 'reason' => '', 'resolution' => 'credit'])->assertSessionHasErrors('reason');
        $this->assertSame(0, SaleReturn::count());
    }

    public function test_credit_comes_off_the_next_memo_and_is_spent_once(): void
    {
        $inv = $this->sale(2);
        $svc = app(ReturnService::class);
        $r = $svc->request($inv, $this->items($inv, 'box', 1), 'other', 'x', 'credit', $this->staff);
        $svc->approve($r, $this->admin);

        $next = $this->sale(2); // 540 less the 270 credit
        $this->assertSame('270.00', (string) $next->total);
        $this->assertSame('270.00', (string) $next->credit_applied);
        $this->assertSame('270.00', (string) $next->paid_amount, 'paid in full means paid what is left');
        $this->assertSame(0.0, $svc->creditBalance($this->shop));

        $third = $this->sale(1);
        $this->assertSame('0.00', (string) $third->credit_applied);
    }

    public function test_credit_bigger_than_the_memo_leaves_the_rest_for_later(): void
    {
        $inv = $this->sale(2);
        $svc = app(ReturnService::class);
        $r = $svc->request($inv, $this->items($inv, 'box', 2), 'other', 'x', 'credit', $this->staff);
        $svc->approve($r, $this->admin); // 540 credit

        $next = $this->sale(1); // 270
        $this->assertSame('0.00', (string) $next->total);
        $this->assertSame(270.0, $svc->creditBalance($this->shop));
    }

    public function test_retail_returns_have_no_credit_and_are_handled_by_hand(): void
    {
        $inv = $this->sale(1, false);
        $svc = app(ReturnService::class);

        try {
            $svc->request($inv, $this->items($inv, 'box', 1), 'other', 'x', 'credit', $this->staff);
            $this->fail('retail has no account');
        } catch (StockException $e) {
            $this->assertStringContainsString('no account', $e->getMessage());
        }

        $r = $svc->request($inv, $this->items($inv, 'box', 1), 'other', 'x', 'cash', $this->staff, 120.0);
        $svc->approve($r, $this->admin);
        $this->assertSame('retail', $r->fresh()->kind);
        $this->assertSame('150.00', (string) $r->fresh()->cash_amount);
        $this->assertSame('120.00', (string) $r->fresh()->expected_money, 'the optional "will receive money" field');
    }

    public function test_only_the_admin_approves_and_staff_can_record(): void
    {
        $inv = $this->sale(1);
        $r = app(ReturnService::class)->request($inv, $this->items($inv, 'box', 1), 'other', 'x', 'credit', $this->staff);

        $this->actingAs($this->staff)->post("/admin/returns/{$r->id}/approve")->assertRedirect('/');
        $this->assertSame('requested', $r->fresh()->status);
        $this->actingAs($this->staff)->get('/admin/returns')->assertInertia(fn (Assert $p) => $p->where('canApprove', false)->where('analysis', null));
    }

    public function test_the_three_searches_find_an_old_sale(): void
    {
        $inv = $this->sale(1);
        $this->actingAs($this->staff);

        $this->get('/admin/returns/find?number='.$inv->number)->assertJsonPath('results.0.id', $inv->id);
        $this->get('/admin/returns/find?date='.today()->toDateString())->assertJsonPath('results.0.id', $inv->id);
        $this->get('/admin/returns/find?medicine=Nap')->assertJsonPath('results.0.lines.0.returnable', true);
        $this->get('/admin/returns/find')->assertJsonPath('results', []);
    }

    public function test_a_return_without_a_memo_is_admin_only_and_marked(): void
    {
        $payload = ['medicine_id' => $this->napa->id, 'unit' => 'piece', 'quantity' => 5, 'price' => 10, 'reason_code' => 'other', 'reason' => 'lost the memo', 'resolution' => 'cash'];

        $this->actingAs($this->staff)->post('/admin/returns/without-invoice', $payload)->assertRedirect('/');
        $this->actingAs($this->admin)->post('/admin/returns/without-invoice', $payload)->assertSessionHasNoErrors();

        $r = SaleReturn::firstOrFail();
        $this->assertTrue($r->without_invoice);
        $before = $this->stock->onHand($this->napa);
        app(ReturnService::class)->approve($r, $this->admin);
        $this->assertEqualsWithDelta($before, $this->stock->onHand($this->napa), 1e-6, 'no lot to go back to, so no stock moves');
    }

    public function test_the_shop_sees_returnable_only_inside_the_window_and_can_ask(): void
    {
        Carbon::setTestNow('2026-10-12 10:00');
        $inv = $this->sale(2);

        $this->actingAs($this->shop)->get('/paikari/returns')->assertInertia(fn (Assert $p) => $p->where('memos.0.lines.0.returnable', true)->where('days', 3));
        $this->post('/paikari/returns', ['invoice_id' => $inv->id, 'items' => $this->items($inv, 'strip', 1), 'reason_code' => 'damaged', 'reason' => 'torn', 'resolution' => 'credit'])->assertSessionHasNoErrors();
        $this->assertSame('requested', SaleReturn::firstOrFail()->status);

        Carbon::setTestNow('2026-10-20 10:00');
        $this->get('/paikari/returns')->assertInertia(fn (Assert $p) => $p->where('memos.0.lines.0.returnable', false));
        $this->post('/paikari/returns', ['invoice_id' => $inv->id, 'items' => $this->items($inv, 'strip', 1), 'reason_code' => 'damaged', 'reason' => 'torn', 'resolution' => 'credit'])->assertSessionHasErrors('return');
        Carbon::setTestNow();
    }

    public function test_one_shop_cannot_return_against_anothers_memo(): void
    {
        $inv = $this->sale(1);
        $other = User::factory()->create(['account_type' => AccountType::Paikari]);

        $this->actingAs($other)->post('/paikari/returns', ['invoice_id' => $inv->id, 'items' => $this->items($inv, 'box', 1), 'reason_code' => 'other', 'reason' => 'x', 'resolution' => 'credit'])->assertRedirect('/');
        $this->assertSame(0, SaleReturn::count());
    }

    public function test_only_a_shop_account_has_a_return_section(): void
    {
        $this->actingAs(User::factory()->create(['account_type' => AccountType::Public]))->get('/paikari/returns')->assertRedirect('/');
        $this->actingAs($this->staff)->get('/paikari/returns')->assertRedirect('/');
    }

    public function test_the_shop_is_warned_when_the_same_mistake_keeps_happening(): void
    {
        $svc = app(ReturnService::class);

        foreach (range(1, 3) as $i) {
            $inv = $this->sale(1);
            $r = $svc->request($inv, $this->items($inv, 'box', 1), 'ordered_by_mistake', 'x', 'credit', $this->staff);
            $svc->approve($r, $this->admin, 'shop');
        }

        $this->assertSame(3, Notice::where('user_id', $this->shop->id)->where('kind', 'shop_mistake')->count());
        $this->assertSame(1, Notice::where('user_id', $this->shop->id)->where('kind', 'repeat_mistake')->count());
    }

    public function test_a_staff_fault_warns_the_staff_member_who_made_the_sale(): void
    {
        $inv = $this->sale(1);
        $svc = app(ReturnService::class);
        $r = $svc->request($inv, $this->items($inv, 'box', 1), 'staff_error', 'x', 'credit', $this->staff);
        $svc->approve($r, $this->admin, 'staff');

        $this->assertSame(1, Notice::where('user_id', $this->staff->id)->where('kind', 'staff_mistake')->count());
    }

    public function test_the_warnings_are_worded_and_staff_can_read_theirs(): void
    {
        $svc = app(ReturnService::class);
        $inv = $this->sale(1);
        $r = $svc->request($inv, $this->items($inv, 'box', 1), 'ordered_by_mistake', 'x', 'credit', $this->staff);
        $svc->approve($r, $this->admin, 'shop');
        $inv2 = $this->sale(1);
        $r2 = $svc->request($inv2, $this->items($inv2, 'box', 1), 'staff_error', 'x', 'credit', $this->staff);
        $svc->approve($r2, $this->admin, 'staff');

        $shop = Notice::where('user_id', $this->shop->id)->where('kind', 'shop_mistake')->firstOrFail();
        $this->assertStringContainsString('Ordered by mistake', app(Messenger::class)->text('shop_mistake', json_decode($shop->body, true), 'en'));
        $this->actingAs($this->shop)->get('/inbox')->assertOk();

        // The staff member has no inbox: the warning waits on the counter screen until they say they have read it.
        $this->actingAs($this->staff)->get('/admin/counter')->assertInertia(fn (AssertableInertia $p) => $p->has('warnings', 1)->where('warnings.0.memo', $inv2->number));
        $this->post('/admin/counter-warnings/read')->assertRedirect();
        $this->get('/admin/counter')->assertInertia(fn (AssertableInertia $p) => $p->has('warnings', 0));
    }

    public function test_the_return_policy_and_credit_show_on_the_memo_and_a_reprint_is_counted(): void
    {
        $inv = $this->sale(1);
        $this->actingAs($this->staff)->get("/admin/counter/{$inv->id}")->assertInertia(fn (Assert $p) => $p->where('policy.days', 3)->where('policy.cash_percent', 50)->where('invoice.print_count', 0));

        $this->post("/admin/counter/{$inv->id}/printed");
        $this->post("/admin/counter/{$inv->id}/printed");
        $this->get("/admin/counter/{$inv->id}")->assertInertia(fn (Assert $p) => $p->where('invoice.print_count', 2));
    }

    public function test_the_return_desk_marks_goods_that_came_back_before_and_unused_credit_warns_the_admin(): void
    {
        $inv = $this->sale(2);
        $svc = app(ReturnService::class);
        $r = $svc->request($inv, $this->items($inv, 'box', 1), 'other', 'x', 'credit', $this->staff);
        $svc->approve($r, $this->admin);

        $this->actingAs($this->staff)->get('/admin/returns/find?number='.$inv->number)->assertJsonPath('results.0.lines.0.previously_returned', true);

        Carbon::setTestNow(now()->addDays(50));
        $this->actingAs($this->admin)->get('/admin/returns')->assertInertia(fn (Assert $p) => $p->where('staleCredit.0.name', $this->shop->name)->where('staleCredit.0.balance', 270));
        Carbon::setTestNow();
    }
}
