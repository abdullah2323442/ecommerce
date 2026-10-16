<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\OfferProduct;
use App\Models\Setting;
use App\Models\StockSource;
use App\Models\Tier;
use App\Models\User;
use App\Services\Sales\InvoiceService;
use App\Services\Sales\ReturnService;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MemoTest extends TestCase
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
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin, 'name' => 'Owner']);
        $this->staff = User::factory()->create(['account_type' => AccountType::Staff, 'name' => 'Robiul']);
        $this->shop = User::factory()->create(['account_type' => AccountType::Paikari, 'name' => 'Rahim Pharmacy', 'phone' => '01711000000', 'tier_id' => Tier::create(['name' => 'B', 'discount_percent' => 10])->id]);
        $this->napa = Medicine::create([
            'company_id' => Company::create(['name' => 'Square'])->id, 'brand_name' => 'Napa', 'strength' => '500 mg', 'form' => 'tablet',
            'packaging_type' => 'box', 'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true,
        ]);
        $this->stock = app(StockService::class);
        $this->invoices = app(InvoiceService::class);
        $this->stock->receive(StockSource::create(['name' => 'Direct', 'type' => 'company', 'default_percent' => 20]), [
            'invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $this->napa->id, 'units' => 20]],
        ]);
        $this->stock->setMasterSync(true);
    }

    private function sale(int $boxes = 2): Invoice
    {
        $draft = $this->invoices->open('paikari', $this->staff, $this->shop);
        $this->invoices->addLine($draft, $this->napa, 'box', $boxes, $this->staff);

        return $this->invoices->finalise($draft, $this->staff);
    }

    public function test_the_year_is_in_the_number_and_counter_and_online_share_one_sequence(): void
    {
        Carbon::setTestNow('2026-12-31 10:00');
        $a = $this->sale(1);
        Carbon::setTestNow('2027-01-02 10:00');
        $b = $this->sale(1);
        $c = $this->sale(1);

        $this->assertSame('M2026-000001', $a->number);
        $this->assertSame('M2027-000001', $b->number, 'a new year starts again');
        $this->assertSame('M2027-000002', $c->number);
        Carbon::setTestNow();
    }

    public function test_the_memo_has_everything_and_offers_come_first_with_their_own_subtotal(): void
    {
        $this->stock->receive(StockSource::create(['name' => 'Big', 'type' => 'company', 'default_percent' => 25]), ['invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $this->napa->id, 'units' => 6]]]);
        $this->actingAs($this->admin)->post('/admin/offer-products', ['medicine_id' => $this->napa->id, 'percent' => 20, 'min_boxes' => 5, 'step_percent' => 0, 'visible_to' => ['paikari'], 'stock_mode' => 'in_stock']);
        $offer = OfferProduct::firstOrFail();

        $draft = $this->invoices->open('paikari', $this->staff, $this->shop);
        $this->invoices->addLine($draft, $this->napa, 'box', 2, $this->staff);
        $this->invoices->addOfferLine($draft, $offer->fresh('medicine'), 5, $this->staff);
        $inv = $this->invoices->finalise($draft, $this->staff, 'bkash');

        $this->actingAs($this->staff)->get("/memo/{$inv->id}")->assertInertia(fn (Assert $p) => $p
            ->component('memo')
            ->where('memo.number', $inv->number)->where('memo.staff', 'Robiul')->where('memo.customer', 'Rahim Pharmacy')->where('memo.payment_method', 'bkash')
            ->where('memo.offer.subtotal', 1200)->where('memo.regular.subtotal', 540)->where('memo.total', 1740)
            ->where('policy.days', 3)->where('policy.cash_percent', 50));
    }

    public function test_credit_shows_as_a_minus_and_the_year_number_is_on_it(): void
    {
        $inv = $this->sale(2);
        $svc = app(ReturnService::class);
        $r = $svc->request($inv, [['line_id' => $inv->lines()->first()->id, 'unit' => 'box', 'quantity' => 1]], 'other', 'x', 'credit', $this->staff);
        $svc->approve($r, $this->admin);
        $next = $this->sale(2);

        $this->actingAs($this->staff)->get("/memo/{$next->id}")->assertInertia(fn (Assert $p) => $p->where('memo.credit', 270)->where('memo.total', 270)->where('memo.paid', 270));
    }

    public function test_a_shop_sees_only_its_own_finished_memo_and_never_cost(): void
    {
        $mine = $this->sale(1);
        $other = User::factory()->create(['account_type' => AccountType::Paikari]);

        $html = $this->actingAs($this->shop)->get("/memo/{$mine->id}")->assertOk()->getContent();
        $this->assertStringNotContainsString('unit_cost', $html);
        $this->assertStringNotContainsString('purchase', $html);
        $this->actingAs($other)->get("/memo/{$mine->id}")->assertRedirect('/');
        $this->actingAs(User::factory()->create(['account_type' => AccountType::Public]))->get("/memo/{$mine->id}")->assertRedirect('/');
    }

    public function test_a_draft_memo_is_not_open_to_the_shop(): void
    {
        $draft = $this->invoices->open('paikari', $this->staff, $this->shop);
        $this->actingAs($this->shop)->get("/memo/{$draft->id}")->assertRedirect('/');
    }

    public function test_the_width_is_58_80_or_a4_and_the_default_is_a_setting_only_the_admin_changes(): void
    {
        $inv = $this->sale(1);
        $this->actingAs($this->staff)->get("/memo/{$inv->id}")->assertInertia(fn (Assert $p) => $p->where('width', '80'));
        $this->get("/memo/{$inv->id}?w=58")->assertInertia(fn (Assert $p) => $p->where('width', '58'));
        $this->get("/memo/{$inv->id}?w=999")->assertInertia(fn (Assert $p) => $p->where('width', '80'));

        $this->put('/admin/print-width', ['width' => '58'])->assertRedirect('/');
        $this->actingAs($this->admin)->put('/admin/print-width', ['width' => '58'])->assertSessionHasNoErrors();
        $this->assertSame('58', Setting::read('print.width'));
        $this->get("/memo/{$inv->id}")->assertInertia(fn (Assert $p) => $p->where('width', '58')->where('isAdmin', true));
    }

    public function test_the_second_print_says_reprint(): void
    {
        $inv = $this->sale(1);
        $this->actingAs($this->staff)->post("/admin/counter/{$inv->id}/printed");
        $this->get("/memo/{$inv->id}")->assertInertia(fn (Assert $p) => $p->where('memo.reprint', false));
        $this->post("/admin/counter/{$inv->id}/printed");
        $this->get("/memo/{$inv->id}")->assertInertia(fn (Assert $p) => $p->where('memo.reprint', true));
    }

    public function test_a_correction_cancels_the_old_memo_keeps_it_and_opens_a_new_draft(): void
    {
        $inv = $this->sale(2);
        $this->assertEqualsWithDelta(18.0, $this->stock->onHand($this->napa), 1e-6);

        $this->actingAs($this->admin)->post("/admin/counter/{$inv->id}/correct")->assertRedirect();

        $this->assertSame('cancelled', $inv->fresh()->status);
        $this->assertSame($inv->number, $inv->fresh()->number, 'the old number stays in history');
        $new = Invoice::where('corrects_invoice_id', $inv->id)->firstOrFail();
        $this->assertSame('draft', $new->status);
        $this->assertSame(1, $new->lines()->count());
        $this->assertEqualsWithDelta(18.0, $this->stock->onHand($this->napa), 1e-6, 'goods are held again by the new draft, not doubled');

        $fixed = $this->invoices->finalise($new, $this->admin);
        $this->assertNotSame($inv->number, $fixed->number);
        $this->get("/memo/{$inv->id}")->assertInertia(fn (Assert $p) => $p->where('memo.cancelled', true));
    }

    public function test_only_the_admin_corrects_and_not_after_a_return(): void
    {
        $inv = $this->sale(2);
        $this->actingAs($this->staff)->post("/admin/counter/{$inv->id}/correct")->assertRedirect('/');
        $this->assertSame('final', $inv->fresh()->status);

        $svc = app(ReturnService::class);
        $svc->request($inv, [['line_id' => $inv->lines()->first()->id, 'unit' => 'box', 'quantity' => 1]], 'other', 'x', 'credit', $this->staff);
        $this->actingAs($this->admin)->post("/admin/counter/{$inv->id}/correct")->assertSessionHasErrors('invoice');
    }

    public function test_a_correction_gives_back_credit_the_old_memo_used(): void
    {
        $first = $this->sale(2);
        $svc = app(ReturnService::class);
        $r = $svc->request($first, [['line_id' => $first->lines()->first()->id, 'unit' => 'box', 'quantity' => 1]], 'other', 'x', 'credit', $this->staff);
        $svc->approve($r, $this->admin);
        $used = $this->sale(2);
        $this->assertSame(0.0, $svc->creditBalance($this->shop));

        $this->actingAs($this->admin)->post("/admin/counter/{$used->id}/correct");
        $this->assertSame(270.0, $svc->creditBalance($this->shop));
    }
}
