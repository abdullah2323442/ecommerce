<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\QuantityStep;
use App\Models\Role;
use App\Models\StockSource;
use App\Models\Tier;
use App\Models\User;
use App\Services\Sales\InvoiceService;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 4: the public price is the same for everyone, but taking more earns a bigger discount. */
class QuantityStepsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $staff;

    private Medicine $napa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $this->staff = User::factory()->create(['account_type' => AccountType::Staff]);
        $this->napa = Medicine::create([
            'company_id' => Company::create(['name' => 'Square'])->id, 'brand_name' => 'Napa', 'strength' => '500 mg', 'form' => 'tablet',
            'packaging_type' => 'box', 'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true,
        ]);
        $stock = app(StockService::class);
        $stock->receive(StockSource::create(['name' => 'Direct', 'type' => 'company', 'default_percent' => 20]), [
            'invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $this->napa->id, 'units' => 100]],
        ]);
        $stock->setMasterSync(true);

        // 1-4: nothing, 5-9: 5%, 10-19: 10%, 20+: 15%  (boxes)
        foreach ([[5, 5], [10, 10], [20, 15]] as [$from, $percent]) {
            QuantityStep::create(['medicine_id' => $this->napa->id, 'unit' => 'box', 'min_qty' => $from, 'kind' => 'percent', 'value' => $percent]);
        }
    }

    private function add(int $boxes, string $unit = 'box')
    {
        return $this->post('/cart', ['medicine_id' => $this->napa->id, 'unit' => $unit, 'quantity' => $boxes]);
    }

    private function details(): array
    {
        return ['name' => 'Hasan Ali', 'phone' => '01711000000', 'district' => 'Dhaka', 'area' => 'Mirpur', 'address' => 'House 5, Road 3', 'delivery_mode' => 'delivery'];
    }

    // ---------------------------------------------------------------- the public cart

    public function test_below_the_first_step_there_is_no_discount_and_the_next_step_is_shown(): void
    {
        $this->add(4);

        $this->get('/cart')->assertInertia(fn (Assert $p) => $p->where('lines.0.unit_price', 300)->where('lines.0.total', 1200)
            ->where('lines.0.step.percent', 0)->where('lines.0.step.next.needs', 1)->where('lines.0.step.next.percent', 5));
    }

    public function test_each_step_sets_the_price_of_every_unit_and_says_how_much_off(): void
    {
        $this->add(5);
        $this->get('/cart')->assertInertia(fn (Assert $p) => $p->where('lines.0.unit_price', 285)->where('lines.0.total', 1425)->where('lines.0.step.percent', 5)->where('lines.0.step.next.needs', 5));

        $this->put('/cart/'.$this->napa->id.':box', ['quantity' => 12]);
        $this->get('/cart')->assertInertia(fn (Assert $p) => $p->where('lines.0.unit_price', 270)->where('lines.0.step.percent', 10));

        $this->put('/cart/'.$this->napa->id.':box', ['quantity' => 25]);
        $this->get('/cart')->assertInertia(fn (Assert $p) => $p->where('lines.0.unit_price', 255)->where('lines.0.step.next', null));
    }

    public function test_a_step_in_taka_takes_that_much_off_each_unit(): void
    {
        QuantityStep::create(['medicine_id' => $this->napa->id, 'unit' => 'strip', 'min_qty' => 3, 'kind' => 'taka', 'value' => 8]);

        $this->add(2, 'strip');
        $this->get('/cart')->assertInertia(fn (Assert $p) => $p->where('lines.0.unit_price', 100));

        $this->add(1, 'strip');
        $this->get('/cart')->assertInertia(fn (Assert $p) => $p->where('lines.0.unit_price', 92)->where('lines.0.total', 276));
    }

    public function test_a_step_belongs_to_its_own_unit(): void
    {
        $this->add(30, 'strip');

        $this->get('/cart')->assertInertia(fn (Assert $p) => $p->where('lines.0.unit_price', 100)->where('lines.0.step.percent', 0)->where('lines.0.step.next', null));
    }

    public function test_a_medicine_with_no_steps_has_no_discount(): void
    {
        QuantityStep::query()->delete();
        $this->add(50);

        $this->get('/cart')->assertInertia(fn (Assert $p) => $p->where('lines.0.unit_price', 300)->where('lines.0.step.percent', 0)->where('lines.0.step.next', null));
    }

    public function test_the_discount_is_the_same_for_everyone_who_pays_the_public_price(): void
    {
        $this->add(10);
        $this->get('/cart')->assertInertia(fn (Assert $p) => $p->where('lines.0.unit_price', 270));

        $this->flushSession();
        $plain = User::factory()->create(['account_type' => AccountType::Public]);
        $this->actingAs($plain)->post('/cart', ['medicine_id' => $this->napa->id, 'unit' => 'box', 'quantity' => 10]);
        $this->get('/cart')->assertInertia(fn (Assert $p) => $p->where('lines.0.unit_price', 270));
    }

    // ---------------------------------------------------------------- the order

    public function test_the_order_keeps_the_step_price_and_follows_the_quantity(): void
    {
        $this->add(10);
        $this->post('/checkout', $this->details())->assertSessionHasNoErrors();

        $order = Invoice::latest('id')->firstOrFail();
        $line = $order->lines()->firstOrFail();
        $this->assertSame('270.00', (string) $line->unit_price);
        $this->assertSame('quantity', $line->rate_layer);
        $this->assertSame('10.000', (string) $line->percent_off);

        // Staff can only supply 4 of the 10 asked for: the buyer keeps the price they saw when they ordered.
        app(InvoiceService::class)->verifyLine($line, 'partial', 4, $this->staff);
        $this->assertSame('270.00', (string) $line->fresh()->unit_price);
    }

    public function test_counter_retail_sales_get_the_step_too_but_a_hand_set_price_is_left_alone(): void
    {
        $this->actingAs($this->staff)->post('/admin/counter', ['kind' => 'retail']);
        $draft = Invoice::latest('id')->firstOrFail();

        $this->post("/admin/counter/{$draft->id}/lines", ['medicine_id' => $this->napa->id, 'unit' => 'box', 'quantity' => 6])->assertSessionHasNoErrors();
        $line = $draft->lines()->firstOrFail();
        $this->assertSame('285.00', (string) $line->unit_price);

        // On an open draft the price follows the quantity both ways.
        app(InvoiceService::class)->setQuantity($line, 12, $this->staff);
        $this->assertSame('270.00', (string) $line->fresh()->unit_price);
        app(InvoiceService::class)->setQuantity($line->fresh(), 2, $this->staff);
        $this->assertSame('300.00', (string) $line->fresh()->unit_price);
        $this->assertSame('mrp', $line->fresh()->rate_layer);

        $this->post("/admin/counter/{$draft->id}/lines", ['medicine_id' => $this->napa->id, 'unit' => 'strip', 'quantity' => 1, 'special_percent' => 4]);
        $this->assertSame('96.00', (string) $draft->lines()->where('unit', 'strip')->firstOrFail()->unit_price);
    }

    public function test_a_shop_buying_at_its_own_rate_is_not_given_the_public_steps(): void
    {
        $shop = User::factory()->create(['account_type' => AccountType::Paikari, 'tier_id' => Tier::create(['name' => 'A', 'discount_percent' => 10])->id]);

        $this->actingAs($this->staff)->post('/admin/counter', ['kind' => 'paikari', 'customer_id' => $shop->id]);
        $draft = Invoice::latest('id')->firstOrFail();
        $this->post("/admin/counter/{$draft->id}/lines", ['medicine_id' => $this->napa->id, 'unit' => 'box', 'quantity' => 12])->assertSessionHasNoErrors();

        $this->assertSame('270.00', (string) $draft->lines()->firstOrFail()->unit_price);   // its own 10%, not 10% from the step on top
        $this->assertNotSame('quantity', $draft->lines()->firstOrFail()->rate_layer);
    }

    // ---------------------------------------------------------------- the product page

    public function test_the_product_page_shows_the_steps_to_everyone(): void
    {
        $this->get("/medicines/{$this->napa->id}")->assertInertia(fn (Assert $p) => $p->has('steps', 3)
            ->where('steps.0.min_qty', 5)->where('steps.0.percent', 5)->where('steps.0.price', 285)->where('steps.2.price', 255));
    }

    public function test_a_medicine_without_steps_shows_none(): void
    {
        QuantityStep::query()->delete();

        $this->get("/medicines/{$this->napa->id}")->assertInertia(fn (Assert $p) => $p->has('steps', 0));
    }

    // ---------------------------------------------------------------- setting them

    public function test_the_admin_sets_the_steps_and_saving_an_empty_list_removes_them(): void
    {
        $steps = [['unit' => 'box', 'min_qty' => 3, 'kind' => 'percent', 'value' => 2], ['unit' => 'strip', 'min_qty' => 6, 'kind' => 'taka', 'value' => 5]];

        $this->actingAs($this->admin)->put("/admin/stock/medicines/{$this->napa->id}/steps", ['steps' => $steps])->assertSessionHasNoErrors();
        $this->assertSame(2, QuantityStep::count());
        $this->get("/admin/stock/medicines/{$this->napa->id}/edit")->assertInertia(fn (Assert $p) => $p->has('steps', 2)->where('units', ['box', 'strip', 'piece']));

        $this->put("/admin/stock/medicines/{$this->napa->id}/steps", ['steps' => []])->assertSessionHasNoErrors();
        $this->assertSame(0, QuantityStep::count());
    }

    public function test_bad_steps_are_refused(): void
    {
        $url = "/admin/stock/medicines/{$this->napa->id}/steps";
        $this->actingAs($this->admin);

        $this->put($url, ['steps' => [['unit' => 'box', 'min_qty' => 1, 'kind' => 'percent', 'value' => 5]]])->assertSessionHasErrors('steps.0.min_qty');
        $this->put($url, ['steps' => [['unit' => 'box', 'min_qty' => 5, 'kind' => 'percent', 'value' => 0]]])->assertSessionHasErrors('steps.0.value');
        $this->put($url, ['steps' => [['unit' => 'crate', 'min_qty' => 5, 'kind' => 'percent', 'value' => 5]]])->assertSessionHasErrors('steps.0.unit');
        $this->put($url, ['steps' => [['unit' => 'box', 'min_qty' => 5, 'kind' => 'percent', 'value' => 95]]])->assertSessionHasErrors('steps.0.value');
        $this->put($url, ['steps' => [['unit' => 'box', 'min_qty' => 5, 'kind' => 'taka', 'value' => 290]]])->assertSessionHasErrors('steps.0.value');
        $this->put($url, ['steps' => [['unit' => 'box', 'min_qty' => 5, 'kind' => 'percent', 'value' => 5], ['unit' => 'box', 'min_qty' => 5, 'kind' => 'taka', 'value' => 5]]])->assertSessionHasErrors('steps.1.min_qty');

        $this->assertSame(3, QuantityStep::count());   // the original three are untouched by every refusal
    }

    public function test_only_those_with_stock_access_set_steps(): void
    {
        $url = "/admin/stock/medicines/{$this->napa->id}/steps";
        $cashier = User::factory()->create(['account_type' => AccountType::Staff, 'role_id' => Role::create(['name' => 'Counter only', 'permissions' => ['counter']])->id]);

        $this->actingAs($cashier)->put($url, ['steps' => []])->assertRedirect('/');
        $shop = User::factory()->create(['account_type' => AccountType::Paikari]);
        $this->actingAs($shop)->put($url, ['steps' => []])->assertRedirect('/');
        $this->assertSame(3, QuantityStep::count());
    }
}
