<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\Setting;
use App\Models\StockSource;
use App\Models\User;
use App\Services\Sales\OrderNotice;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ShopHabitsTest extends TestCase
{
    use RefreshDatabase;

    private User $shop;

    private User $staff;

    private User $admin;

    private Medicine $napa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = User::factory()->create(['account_type' => AccountType::Paikari]);
        $this->staff = User::factory()->create(['account_type' => AccountType::Staff]);
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $this->napa = Medicine::create([
            'company_id' => Company::create(['name' => 'Square'])->id, 'brand_name' => 'Napa', 'strength' => '500 mg', 'form' => 'tablet',
            'packaging_type' => 'box', 'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true,
        ]);
        $stock = app(StockService::class);
        $stock->receive(StockSource::create(['name' => 'Direct', 'type' => 'company', 'default_percent' => 20]), [
            'invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $this->napa->id, 'units' => 50]],
        ]);
        $stock->setMasterSync(true);
    }

    private function checkedOrder(int $boxes): Invoice
    {
        $this->actingAs($this->shop)->post('/paikari/cart', ['medicine_id' => $this->napa->id, 'unit' => 'box', 'quantity' => $boxes]);
        $this->post('/paikari/order', ['delivery_mode' => 'collect']);
        $order = Invoice::latest('id')->firstOrFail();
        $this->actingAs($this->staff)->post("/admin/orders/{$order->id}/lines/{$order->lines()->first()->id}/verify", ['status' => 'full']);
        $this->post("/admin/orders/{$order->id}/complete");

        return $order->fresh();
    }

    public function test_a_medicine_ordered_twice_shows_up_as_a_usual_order(): void
    {
        $this->checkedOrder(4);
        $this->actingAs($this->shop)->get('/paikari')->assertInertia(fn (Assert $p) => $p->where('usual', []));

        $this->checkedOrder(6);
        $this->actingAs($this->shop)->get('/paikari')->assertInertia(fn (Assert $p) => $p->where('usual.0.name', 'Napa')->where('usual.0.unit', 'box')->where('usual.0.quantity', 5));
    }

    public function test_notice_is_holiday_on_friday_night_after_ten_and_none_midday(): void
    {
        $notice = new OrderNotice;

        $this->assertSame('holiday', $notice->at(Carbon::parse('2026-10-16 12:00')));  // a Friday
        $this->assertSame('night', $notice->at(Carbon::parse('2026-10-14 23:30')));
        $this->assertSame('night', $notice->at(Carbon::parse('2026-10-14 03:00')));
        $this->assertNull($notice->at(Carbon::parse('2026-10-14 12:00')));

        Setting::write('orders.holidays', ['2026-10-14']);
        $this->assertSame('holiday', $notice->at(Carbon::parse('2026-10-14 12:00')));
    }

    public function test_an_order_at_night_is_still_taken(): void
    {
        Carbon::setTestNow('2026-10-14 23:30');
        $this->actingAs($this->shop)->post('/paikari/cart', ['medicine_id' => $this->napa->id, 'unit' => 'box', 'quantity' => 1]);
        $this->post('/paikari/order', ['delivery_mode' => 'collect'])->assertSessionHasNoErrors();

        $this->assertSame('placed', Invoice::firstOrFail()->status);
        Carbon::setTestNow();
    }

    public function test_the_monthly_total_is_hidden_until_the_admin_switches_it_on_and_shows_only_at_month_end(): void
    {
        Carbon::setTestNow('2026-10-30 12:00'); // the last days of the month
        $order = $this->checkedOrder(2);
        $this->actingAs($this->staff)->post("/admin/counter/{$order->id}/finalise", ['payment_method' => 'cash']);

        $this->actingAs($this->shop)->get('/paikari')->assertInertia(fn (Assert $p) => $p->where('monthTotal', null));

        $this->actingAs($this->staff)->put("/admin/customers/{$this->shop->id}/monthly-total", ['show' => true])->assertRedirect('/');
        $this->actingAs($this->admin)->put("/admin/customers/{$this->shop->id}/monthly-total", ['show' => true])->assertSessionHasNoErrors();

        $this->actingAs($this->shop->fresh())->get('/paikari')->assertInertia(fn (Assert $p) => $p->where('monthTotal', 600));

        Carbon::setTestNow('2026-10-12 12:00'); // mid-month: nothing shown
        $this->actingAs($this->shop->fresh())->get('/paikari')->assertInertia(fn (Assert $p) => $p->where('monthTotal', null));
        Carbon::setTestNow();
    }
}
