<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\Setting;
use App\Models\StockSource;
use App\Models\User;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Spec ch 13: the buyer can change or cancel their own order for a short time, but not once staff have started on it. */
class EditPlacedOrderTest extends TestCase
{
    use RefreshDatabase;

    private Medicine $napa;

    private Medicine $seclo;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 10:00:00');
        $this->staff = User::factory()->create(['account_type' => AccountType::Staff]);
        $company = Company::create(['name' => 'Square']);
        $mk = fn (string $n) => Medicine::create([
            'company_id' => $company->id, 'brand_name' => $n, 'strength' => '500 mg', 'form' => 'tablet', 'packaging_type' => 'box', 'strips_per_box' => 3, 'pieces_per_strip' => 10,
            'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true,
        ]);
        $this->napa = $mk('Napa');
        $this->seclo = $mk('Seclo');
        $stock = app(StockService::class);
        $stock->receive(StockSource::create(['name' => 'Direct', 'type' => 'company', 'default_percent' => 20]), ['invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $this->napa->id, 'units' => 20], ['medicine_id' => $this->seclo->id, 'units' => 20]]]);
        $stock->setMasterSync(true);
        config(['brand.address' => 'Shop 4, Mirpur']);
        Setting::write('delivery.shop_district', 'Dhaka');
    }

    private function order(bool $two = true, string $mode = 'delivery'): Invoice
    {
        $this->post('/cart', ['medicine_id' => $this->napa->id, 'unit' => 'strip', 'quantity' => 2]);
        if ($two) {
            $this->post('/cart', ['medicine_id' => $this->seclo->id, 'unit' => 'strip', 'quantity' => 1]);
        }
        $this->post('/checkout', ['name' => 'Hasan', 'phone' => '01711000000', 'district' => 'Dhaka', 'area' => 'Mirpur', 'address' => 'House 5', 'delivery_mode' => $mode])->assertSessionHasNoErrors();

        return Invoice::latest('id')->firstOrFail();
    }

    private function line(Invoice $o, Medicine $m)
    {
        return $o->lines()->where('medicine_id', $m->id)->firstOrFail();
    }

    public function test_the_buyer_changes_an_amount_and_the_total_follows(): void
    {
        $o = $this->order();
        $napa = $this->line($o, $this->napa);

        $this->put("/orders/{$o->public_token}/lines/{$napa->id}", ['quantity' => 5])->assertSessionHasNoErrors();

        $napa->refresh();
        $this->assertSame('5.00', (string) $napa->quantity);
        $this->assertSame('5.00', (string) $napa->requested_quantity);
        $this->assertSame('500.00', number_format($napa->lineTotal(), 2, '.', ''));
    }

    public function test_the_buyer_takes_one_item_off_but_never_the_last(): void
    {
        $o = $this->order();
        $seclo = $this->line($o, $this->seclo);

        $this->delete("/orders/{$o->public_token}/lines/{$seclo->id}")->assertSessionHasNoErrors();
        $this->assertSame(1, $o->lines()->count());

        $napa = $this->line($o, $this->napa);
        $this->delete("/orders/{$o->public_token}/lines/{$napa->id}")->assertSessionHasErrors('order');
        $this->assertSame(1, $o->lines()->count());
    }

    public function test_a_whole_number_more_than_zero_only(): void
    {
        $o = $this->order();
        $napa = $this->line($o, $this->napa);

        $this->put("/orders/{$o->public_token}/lines/{$napa->id}", ['quantity' => 0])->assertSessionHasErrors('quantity');
        $this->put("/orders/{$o->public_token}/lines/{$napa->id}", ['quantity' => 2.5])->assertSessionHasErrors('order');
        $this->put("/orders/{$o->public_token}/lines/{$napa->id}", ['quantity' => 999999])->assertSessionHasErrors('quantity');
        $this->assertSame('2.00', (string) $napa->fresh()->quantity);
    }

    public function test_after_the_short_time_it_cannot_be_changed(): void
    {
        $o = $this->order();
        $napa = $this->line($o, $this->napa);

        Carbon::setTestNow(now()->addMinutes(25));

        $this->put("/orders/{$o->public_token}/lines/{$napa->id}", ['quantity' => 5])->assertSessionHasErrors('order');
        $this->delete("/orders/{$o->public_token}/lines/{$this->line($o, $this->seclo)->id}")->assertSessionHasErrors('order');
        $this->assertSame('2.00', (string) $napa->fresh()->quantity);
    }

    public function test_once_staff_start_picking_it_cannot_be_changed(): void
    {
        $o = $this->order();
        $napa = $this->line($o, $this->napa);
        $this->actingAs($this->staff)->post("/admin/orders/{$o->id}/lines/{$napa->id}/verify", ['status' => 'full']);
        auth()->logout();

        $this->put("/orders/{$o->public_token}/lines/{$napa->id}", ['quantity' => 5])->assertSessionHasErrors('order');
        $this->assertSame('2.00', (string) $napa->fresh()->quantity);
    }

    public function test_a_line_of_someone_elses_order_cannot_be_touched_through_my_link(): void
    {
        $mine = $this->order(false);
        $this->flushSession();
        $theirs = $this->order(false);
        $theirLine = $this->line($theirs, $this->napa);

        $this->put("/orders/{$mine->public_token}/lines/{$theirLine->id}", ['quantity' => 9])->assertRedirect('/');
        $this->assertSame('2.00', (string) $theirLine->fresh()->quantity);
    }

    public function test_the_delivery_charge_follows_what_is_left(): void
    {
        Setting::write('delivery.free_over', 250);
        $o = $this->order();                       // 2 strips and 1 strip: 300, over the line, so delivery is free
        $this->assertSame('0.00', (string) $o->delivery_charge);

        $this->delete("/orders/{$o->public_token}/lines/{$this->line($o, $this->seclo)->id}")->assertSessionHasNoErrors();   // 200 left

        $this->assertSame('60.00', (string) $o->fresh()->delivery_charge, 'under the line, the charge is back');
    }

    public function test_a_cancelled_order_cannot_be_changed(): void
    {
        $o = $this->order();
        $napa = $this->line($o, $this->napa);
        $this->post("/orders/{$o->public_token}/cancel");

        $this->put("/orders/{$o->public_token}/lines/{$napa->id}", ['quantity' => 5])->assertSessionHasErrors('order');
    }

    public function test_the_page_says_when_items_can_still_be_changed(): void
    {
        $o = $this->order();

        $this->get("/orders/{$o->public_token}")->assertInertia(fn ($p) => $p->where('order.can_edit_items', true));

        Carbon::setTestNow(now()->addMinutes(25));
        $this->get("/orders/{$o->public_token}")->assertInertia(fn ($p) => $p->where('order.can_edit_items', false));
    }
}
