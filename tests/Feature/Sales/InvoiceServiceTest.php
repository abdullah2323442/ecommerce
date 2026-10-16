<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\CustomerRate;
use App\Models\Medicine;
use App\Models\Setting;
use App\Models\StockSource;
use App\Models\Tier;
use App\Models\User;
use App\Services\Sales\InvoiceService;
use App\Services\Stock\StockException;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceServiceTest extends TestCase
{
    use RefreshDatabase;

    private InvoiceService $invoices;

    private StockService $stock;

    private User $staff;

    private User $admin;

    private User $paikari;

    private Medicine $napa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->invoices = app(InvoiceService::class);
        $this->stock = app(StockService::class);
        $this->staff = User::factory()->create(['account_type' => AccountType::Staff]);
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $tier = Tier::create(['name' => 'A', 'discount_percent' => 10]);
        $this->paikari = User::factory()->create(['account_type' => AccountType::Paikari, 'tier_id' => $tier->id]);

        // 3 strips of 10 pieces per box: box 300, strip 100, piece 10.
        $this->napa = Medicine::create([
            'company_id' => Company::create(['name' => 'Square'])->id, 'brand_name' => 'Napa', 'strength' => '500 mg', 'form' => 'tablet',
            'packaging_type' => 'box', 'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true,
        ]);

        $source = StockSource::create(['name' => 'Direct', 'type' => 'company', 'default_percent' => 20]); // cost 240 per box
        $this->stock->receive($source, ['invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $this->napa->id, 'units' => 10]]]);
        $this->stock->setMasterSync(true);
    }

    // ---- units

    public function test_a_strip_and_a_piece_are_fractions_of_a_box(): void
    {
        $this->assertSame(1.0, InvoiceService::fraction($this->napa, 'box'));
        $this->assertEqualsWithDelta(1 / 3, InvoiceService::fraction($this->napa, 'strip'), 1e-9);
        $this->assertEqualsWithDelta(1 / 30, InvoiceService::fraction($this->napa, 'piece'), 1e-9);
        $this->assertSame(100.0, InvoiceService::unitMrp($this->napa, 'strip'));
    }

    // ---- retail

    public function test_retail_is_sold_at_mrp_and_takes_stock_out(): void
    {
        $draft = $this->invoices->open('retail', $this->staff);
        $line = $this->invoices->addLine($draft, $this->napa, 'strip', 6, $this->staff);

        $this->assertSame('mrp', $line->rate_layer);
        $this->assertSame('100.00', (string) $line->unit_price);
        $this->assertEqualsWithDelta(8.0, $this->stock->onHand($this->napa), 1e-6, '6 strips = 2 boxes out of 10');
    }

    public function test_retail_drafts_are_numbered_customer_1_2_3(): void
    {
        $this->assertSame('Customer 1', $this->invoices->open('retail', $this->staff)->label);
        $this->assertSame('Customer 2', $this->invoices->open('retail', $this->staff)->label);
        $this->assertSame('Walk-in Rahim', $this->invoices->open('retail', $this->staff, null, 'counter', 'Walk-in Rahim')->label);
    }

    public function test_the_end_discount_skips_lines_that_already_have_a_special_discount(): void
    {
        $draft = $this->invoices->open('retail', $this->staff);
        $this->invoices->addLine($draft, $this->napa, 'box', 1, $this->staff);                        // 300, eligible
        $this->invoices->addLine($draft, $this->napa, 'strip', 3, $this->staff, 5.0, false, true);    // 285, own 5%: not discounted twice
        $this->invoices->setEndDiscount($draft, 5, $this->staff);

        $totals = $this->invoices->totals($draft->fresh());

        $this->assertSame(585.0, $totals['subtotal']);
        $this->assertSame(300.0, $totals['eligible']);
        $this->assertSame(15.0, $totals['discount']);
        $this->assertSame(570.0, $totals['total']);
    }

    // ---- paikari / wholesale rates

    public function test_a_paikari_invoice_uses_the_customers_tier_rate(): void
    {
        $draft = $this->invoices->open('paikari', $this->staff, $this->paikari);
        $line = $this->invoices->addLine($draft, $this->napa, 'box', 2, $this->staff);

        $this->assertSame('tier', $line->rate_layer);
        $this->assertSame('270.00', (string) $line->unit_price);
    }

    public function test_priority_is_line_percent_then_invoice_percent_then_customer_rate(): void
    {
        CustomerRate::create(['user_id' => $this->paikari->id, 'medicine_id' => $this->napa->id, 'discount_percent' => 12]);
        $draft = $this->invoices->open('paikari', $this->admin, $this->paikari);

        $customer = $this->invoices->addLine($draft, $this->napa, 'box', 1, $this->admin);
        $this->assertSame('customer_manual', $customer->rate_layer);
        $this->assertSame('264.00', (string) $customer->unit_price);

        $this->invoices->setInvoicePercent($draft, 15, $this->admin);
        $this->assertSame('invoice', $customer->fresh()->rate_layer, 'the invoice-top % beats the customer rate');
        $this->assertSame('255.00', (string) $customer->fresh()->unit_price);

        $special = $this->invoices->addLine($draft, $this->napa, 'box', 1, $this->admin, 20.0, false, true);
        $this->assertSame('special', $special->rate_layer, 'the line % beats both');
        $this->assertSame('240.00', (string) $special->unit_price);
    }

    public function test_a_non_retail_draft_needs_a_customer(): void
    {
        $this->expectException(StockException::class);
        $this->invoices->open('paikari', $this->staff);
    }

    // ---- stock moves with the lines

    public function test_changing_a_quantity_puts_back_the_old_stock_and_takes_the_new(): void
    {
        $draft = $this->invoices->open('retail', $this->staff);
        $line = $this->invoices->addLine($draft, $this->napa, 'box', 4, $this->staff);
        $this->assertEqualsWithDelta(6.0, $this->stock->onHand($this->napa), 1e-6);

        $this->invoices->setQuantity($line, 1, $this->staff);
        $this->assertEqualsWithDelta(9.0, $this->stock->onHand($this->napa), 1e-6);
    }

    public function test_removing_a_line_returns_its_stock_and_keeps_the_history(): void
    {
        $draft = $this->invoices->open('retail', $this->staff);
        $line = $this->invoices->addLine($draft, $this->napa, 'box', 4, $this->staff);

        $this->invoices->removeLine($line, $this->staff);

        $this->assertEqualsWithDelta(10.0, $this->stock->onHand($this->napa), 1e-6);
        $this->assertSame(0, $draft->lines()->count());
        $this->assertSame(1, $draft->allLines()->count(), 'the removed line stays for the history');
        $this->assertDatabaseHas('stock_movements', ['invoice_line_id' => $line->id, 'type' => 'return']);
        $this->assertDatabaseHas('stock_movements', ['invoice_line_id' => $line->id, 'type' => 'sale', 'reversed' => true]);
    }

    public function test_removing_twice_does_not_return_stock_twice(): void
    {
        $draft = $this->invoices->open('retail', $this->staff);
        $line = $this->invoices->addLine($draft, $this->napa, 'box', 4, $this->staff);

        $this->stock->restore($line->id);
        $this->stock->restore($line->id);

        $this->assertEqualsWithDelta(10.0, $this->stock->onHand($this->napa), 1e-6);
    }

    public function test_cancelling_a_draft_returns_everything(): void
    {
        $draft = $this->invoices->open('retail', $this->staff);
        $this->invoices->addLine($draft, $this->napa, 'box', 2, $this->staff);
        $this->invoices->addLine($draft, $this->napa, 'strip', 3, $this->staff);

        $this->invoices->cancel($draft, $this->staff);

        $this->assertEqualsWithDelta(10.0, $this->stock->onHand($this->napa), 1e-6);
        $this->assertSame('cancelled', $draft->fresh()->status);
    }

    public function test_the_same_medicine_in_the_same_unit_merges_into_one_line(): void
    {
        $draft = $this->invoices->open('retail', $this->staff);
        $this->invoices->addLine($draft, $this->napa, 'box', 1, $this->staff);
        $this->invoices->addLine($draft, $this->napa, 'box', 2, $this->staff);

        $this->assertSame(1, $draft->lines()->count());
        $this->assertSame('3.00', (string) $draft->lines()->first()->quantity);
        $this->assertEqualsWithDelta(7.0, $this->stock->onHand($this->napa), 1e-6);
    }

    public function test_a_line_from_outside_is_priced_but_takes_no_stock(): void
    {
        $draft = $this->invoices->open('retail', $this->staff);
        $line = $this->invoices->addLine($draft, $this->napa, 'box', 5, $this->staff, null, true);

        $this->assertTrue($line->is_outside);
        $this->assertSame(10.0, $this->stock->onHand($this->napa));
        $this->assertSame(1500.0, $this->invoices->totals($draft)['subtotal']);
    }

    public function test_master_sync_off_means_retail_takes_no_stock_but_the_invoice_still_works(): void
    {
        $this->stock->setMasterSync(false);
        $draft = $this->invoices->open('retail', $this->staff);
        $this->invoices->addLine($draft, $this->napa, 'box', 3, $this->staff);

        $this->assertSame(10.0, $this->stock->onHand($this->napa));
        $this->assertSame(900.0, $this->invoices->totals($draft)['subtotal']);
    }

    public function test_selling_more_than_is_in_stock_is_refused_and_nothing_is_left_half_done(): void
    {
        $draft = $this->invoices->open('retail', $this->staff);

        try {
            $this->invoices->addLine($draft, $this->napa, 'box', 11, $this->staff);
            $this->fail('Overselling should be refused.');
        } catch (StockException) {
            $this->assertSame(10.0, $this->stock->onHand($this->napa));
            $this->assertSame(0, $draft->allLines()->count(), 'the line is not kept when its stock could not be taken');
        }
    }

    // ---- limits

    public function test_staff_cannot_discount_past_their_limit_but_the_admin_can(): void
    {
        $draft = $this->invoices->open('retail', $this->staff);

        try {
            $this->invoices->setEndDiscount($draft, 8, $this->staff);
            $this->fail('Staff are limited to 5% by default.');
        } catch (StockException $e) {
            $this->assertStringContainsString('admin', $e->getMessage());
        }

        $this->invoices->setEndDiscount($draft, 5, $this->staff);
        $this->invoices->setEndDiscount($draft, 8, $this->admin);
        $this->assertSame('8.00', (string) $draft->fresh()->end_discount_percent);

        Setting::write('counter.staff_discount_limit', 10);
        $this->invoices->setEndDiscount($draft, 9, $this->staff);
        $this->assertSame('9.00', (string) $draft->fresh()->end_discount_percent);
    }

    public function test_staff_cannot_sell_below_cost_even_at_the_counter(): void
    {
        $draft = $this->invoices->open('paikari', $this->admin, $this->paikari);
        $draft = $this->invoices->setInvoicePercent($draft, 5, $this->admin);

        $this->expectException(StockException::class); // cost 240 per box, 5 staff-limit ok, but a 25% line is above the limit first
        $this->invoices->addLine($draft, $this->napa, 'box', 1, $this->staff, 25.0);
    }

    public function test_an_admin_loss_sale_is_flagged(): void
    {
        $draft = $this->invoices->open('paikari', $this->admin, $this->paikari);
        $this->invoices->addLine($draft, $this->napa, 'box', 1, $this->admin, 25.0); // 225 against a cost of 240

        $this->assertDatabaseHas('stock_movements', ['type' => 'sale', 'is_loss' => true]);
    }

    // ---- merge, move, finalise

    public function test_two_drafts_for_the_same_customer_merge_and_remember_where_lines_came_from(): void
    {
        $a = $this->invoices->open('paikari', $this->staff, $this->paikari);
        $b = $this->invoices->open('paikari', $this->staff, $this->paikari);
        $this->invoices->addLine($a, $this->napa, 'box', 1, $this->staff);
        $this->invoices->addLine($b, $this->napa, 'strip', 3, $this->staff);

        $merged = $this->invoices->merge($a, $b);

        $this->assertSame(2, $merged->lines()->count());
        $this->assertSame($b->id, $merged->lines()->where('unit', 'strip')->first()->from_invoice_id);
        $this->assertSame('cancelled', $b->fresh()->status);
        $this->assertEqualsWithDelta(8.0, $this->stock->onHand($this->napa), 1e-6, 'merging moves lines, it does not touch stock');
    }

    public function test_drafts_for_different_customers_do_not_merge(): void
    {
        $other = User::factory()->create(['account_type' => AccountType::Paikari]);
        $a = $this->invoices->open('paikari', $this->staff, $this->paikari);
        $b = $this->invoices->open('paikari', $this->staff, $other);

        $this->expectException(StockException::class);
        $this->invoices->merge($a, $b);
    }

    public function test_moving_a_line_reprices_it_for_the_new_customer_without_moving_stock(): void
    {
        $other = User::factory()->create(['account_type' => AccountType::Paikari, 'tier_id' => Tier::create(['name' => 'C', 'discount_percent' => 20])->id]);
        $a = $this->invoices->open('paikari', $this->staff, $this->paikari);
        $b = $this->invoices->open('paikari', $this->staff, $other);
        $line = $this->invoices->addLine($a, $this->napa, 'box', 1, $this->staff);

        $moved = $this->invoices->moveLine($line, $b);

        $this->assertSame($b->id, $moved->invoice_id);
        $this->assertSame('240.00', (string) $moved->unit_price, 'the new customer pays their own 20%');
        $this->assertEqualsWithDelta(9.0, $this->stock->onHand($this->napa), 1e-6);
    }

    public function test_finalising_freezes_the_totals_and_numbers_the_memo_in_order(): void
    {
        $one = $this->invoices->open('retail', $this->staff);
        $this->invoices->addLine($one, $this->napa, 'box', 1, $this->staff);
        $two = $this->invoices->open('retail', $this->staff);
        $this->invoices->addLine($two, $this->napa, 'strip', 3, $this->staff);

        $a = $this->invoices->finalise($one, $this->staff);
        $b = $this->invoices->finalise($two, $this->staff);

        $this->assertSame('M'.now()->year.'-000001', $a->number);
        $this->assertSame('M'.now()->year.'-000002', $b->number);
        $this->assertSame('300.00', (string) $a->total);
        $this->assertSame('final', $a->status);
    }

    public function test_an_empty_or_closed_invoice_cannot_be_finalised_or_edited(): void
    {
        $draft = $this->invoices->open('retail', $this->staff);

        try {
            $this->invoices->finalise($draft, $this->staff);
            $this->fail('An empty invoice cannot be finalised.');
        } catch (StockException) {
            // expected
        }

        $this->invoices->addLine($draft, $this->napa, 'box', 1, $this->staff);
        $done = $this->invoices->finalise($draft, $this->staff);

        $this->expectException(StockException::class);
        $this->invoices->addLine($done, $this->napa, 'box', 1, $this->staff);
    }
}
