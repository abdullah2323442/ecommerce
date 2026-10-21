<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Models\Company;
use App\Models\DeliveryRating;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\Notice;
use App\Models\Role;
use App\Models\StockSource;
use App\Models\User;
use App\Services\Messages\SmsGateway;
use App\Services\Sales\Deliveries;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 20: our own delivery people: their list by area, "arrived", the buyer's code or a photo, the money they hold, and the buyer's rating. */
class DeliveriesTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $rider;

    private User $rider2;

    private Medicine $napa;

    private \ArrayObject $texts;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        $this->staff = User::factory()->create(['account_type' => AccountType::Staff, 'name' => 'Office Staff']);
        $role = Role::where('name', 'Delivery person')->firstOrFail();
        $this->rider = User::factory()->create(['account_type' => AccountType::Staff, 'role_id' => $role->id, 'name' => 'Rider One']);
        $this->rider2 = User::factory()->create(['account_type' => AccountType::Staff, 'role_id' => $role->id, 'name' => 'Rider Two']);
        $this->napa = Medicine::create([
            'company_id' => Company::create(['name' => 'Square'])->id, 'brand_name' => 'Napa', 'strength' => '500 mg', 'form' => 'tablet',
            'packaging_type' => 'box', 'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true,
        ]);
        $stock = app(StockService::class);
        $stock->receive(StockSource::create(['name' => 'Direct', 'type' => 'company', 'default_percent' => 20]), [
            'invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $this->napa->id, 'units' => 50]],
        ]);
        $stock->setMasterSync(true);

        $this->texts = new \ArrayObject;
        $this->app->instance(SmsGateway::class, new class($this->texts) implements SmsGateway
        {
            public function __construct(private \ArrayObject $texts) {}

            public function send(string $phone, string $text): void
            {
                $this->texts[] = $phone.' '.$text;
            }
        });
    }

    /** A guest order in an area, checked by staff and ready to go. */
    private function order(string $area = 'Mirpur', int $strips = 2): Invoice
    {
        auth()->logout();
        $this->post('/cart', ['medicine_id' => $this->napa->id, 'unit' => 'strip', 'quantity' => $strips]);
        $this->post('/checkout', ['name' => 'Hasan', 'phone' => '01711000000', 'district' => 'Dhaka', 'area' => $area, 'address' => 'House 5', 'how_to_find' => 'Red gate', 'delivery_mode' => 'delivery']);
        $order = Invoice::latest('id')->firstOrFail();
        $this->actingAs($this->staff)->post("/admin/orders/{$order->id}/lines/{$order->lines()->firstOrFail()->id}/verify", ['status' => 'full']);
        $this->post("/admin/orders/{$order->id}/complete")->assertSessionHasNoErrors();
        auth()->logout();

        return $order->fresh();
    }

    private function assign(Invoice $order, ?User $rider = null): Invoice
    {
        $this->actingAs($this->staff)->post("/admin/orders/{$order->id}/assign", ['person_id' => ($rider ?? $this->rider)->id])->assertSessionHasNoErrors();

        return $order->fresh();
    }

    // ------------------------------------------------------------------ assigning

    public function test_sending_with_our_own_person_sends_the_order_and_makes_a_code(): void
    {
        $order = $this->assign($this->order());

        $this->assertNotNull($order->sent_at);
        $this->assertSame($this->rider->id, $order->delivery_person_id);
        $this->assertSame($this->staff->id, $order->handled_by);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $order->delivery_code);
        $this->assertCount(1, $this->texts, 'the code is sent to the buyer\'s phone');
        $this->assertStringContainsString('01711000000', $this->texts[0]);
        $this->assertStringContainsString($order->delivery_code, $this->texts[0]);
    }

    public function test_only_one_of_our_delivery_people_can_be_picked_and_only_by_the_office(): void
    {
        $order = $this->order();

        $this->actingAs($this->staff)->post("/admin/orders/{$order->id}/assign", ['person_id' => $this->staff->id])->assertSessionHasErrors('delivery');
        $this->actingAs($this->rider)->post("/admin/orders/{$order->id}/assign", ['person_id' => $this->rider->id])->assertRedirect('/');
        $this->assertNull($order->fresh()->delivery_person_id);
    }

    public function test_a_courier_or_collected_order_cannot_be_given_to_a_delivery_person(): void
    {
        $order = $this->order();
        $order->update(['delivery_mode' => 'collect']);

        $this->actingAs($this->staff)->post("/admin/orders/{$order->id}/assign", ['person_id' => $this->rider->id])->assertSessionHasErrors('delivery');
    }

    public function test_it_can_be_given_to_someone_else_before_they_arrive_and_the_code_stays(): void
    {
        $order = $this->assign($this->order());
        $code = $order->delivery_code;

        $order = $this->assign($order, $this->rider2);

        $this->assertSame($this->rider2->id, $order->delivery_person_id);
        $this->assertSame($code, $order->delivery_code);
        $this->assertCount(1, $this->texts, 'the buyer is not sent a second code');

        $this->actingAs($this->rider2)->post("/admin/deliveries/{$order->id}/arrived");
        $this->actingAs($this->staff)->post("/admin/orders/{$order->id}/assign", ['person_id' => $this->rider->id])->assertSessionHasErrors('delivery');
    }

    // ------------------------------------------------------------------ the person's list

    public function test_the_list_is_only_mine_grouped_by_area_with_the_money_to_collect(): void
    {
        $this->assign($this->order('Mirpur'));
        $this->assign($this->order('Gulshan'));
        $this->assign($this->order('Mirpur', 3));
        $this->assign($this->order('Uttara'), $this->rider2);

        $this->actingAs($this->rider)->get('/admin/deliveries')->assertInertia(fn (Assert $p) => $p->component('admin/deliveries')
            ->has('groups', 2)->where('groups.0.area', 'Gulshan')->where('groups.1.area', 'Mirpur')->has('groups.1.orders', 2)
            ->where('groups.1.orders.0.collect', fn ($c) => $c > 0)->where('groups.1.orders.0.how_to_find', 'Red gate'));
    }

    public function test_the_list_never_carries_the_code(): void
    {
        $order = $this->assign($this->order());

        $json = $this->actingAs($this->rider)->get('/admin/deliveries')->viewData('page')['props'];

        $this->assertStringNotContainsString($order->delivery_code, json_encode($json['groups']));
    }

    public function test_the_office_page_for_the_order_never_carries_the_code_either(): void
    {
        $order = $this->assign($this->order());

        $props = $this->actingAs($this->staff)->get("/admin/orders/{$order->id}")->viewData('page')['props'];

        $this->assertStringNotContainsString('"delivery_code"', json_encode($props));
        $this->assertSame('Rider One', $props['order']['delivery_person']);
    }

    public function test_the_screen_is_only_for_delivery_people(): void
    {
        $this->actingAs($this->staff)->get('/admin/deliveries')->assertRedirect('/');
        $this->actingAs(User::factory()->create(['account_type' => AccountType::Public]))->get('/admin/deliveries')->assertRedirect('/');
        $this->actingAs($this->rider)->get('/admin/deliveries')->assertOk();
    }

    public function test_a_delivery_person_sees_nothing_of_the_rest_of_the_office(): void
    {
        $this->actingAs($this->rider)->get('/admin/orders')->assertRedirect('/');
        $this->actingAs($this->rider)->get('/admin/delivery-cash')->assertRedirect('/');
        $this->actingAs($this->rider)->get('/admin/staff')->assertRedirect('/');
    }

    // ------------------------------------------------------------------ arrived

    public function test_arrived_tells_the_buyer_once_and_the_page_shows_it(): void
    {
        $buyer = User::factory()->create(['account_type' => AccountType::Public, 'phone' => '01711000000']);
        $order = $this->order();
        $order->update(['customer_id' => $buyer->id]);
        $order = $this->assign($order);

        $this->actingAs($this->rider)->post("/admin/deliveries/{$order->id}/arrived")->assertSessionHasNoErrors();
        $this->actingAs($this->rider)->post("/admin/deliveries/{$order->id}/arrived")->assertSessionHasNoErrors();

        $this->assertNotNull($order->fresh()->arrived_at);
        $this->assertSame(1, Notice::where('user_id', $buyer->id)->where('kind', 'delivery_arrived')->count());
        auth()->logout();
        $this->get("/orders/{$order->public_token}")->assertInertia(fn (Assert $p) => $p->where('order.arrived', true)->where('order.own_delivery', true)->where('order.code', $order->delivery_code));
    }

    public function test_someone_elses_order_is_not_on_my_list(): void
    {
        $order = $this->assign($this->order());

        $this->actingAs($this->rider2)->post("/admin/deliveries/{$order->id}/arrived")->assertSessionHasErrors('delivery');
        $this->actingAs($this->rider2)->post("/admin/deliveries/{$order->id}/confirm", ['code' => $order->delivery_code])->assertSessionHasErrors('delivery');
        $this->assertNull($order->fresh()->delivered_at);
    }

    // ------------------------------------------------------------------ proof

    public function test_the_right_code_delivers_it_and_makes_the_sale_complete(): void
    {
        $order = $this->assign($this->order());
        $this->assertSame('verified', $order->status);

        $this->actingAs($this->rider)->post("/admin/deliveries/{$order->id}/confirm", ['code' => $order->delivery_code])->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertNotNull($order->delivered_at);
        $this->assertSame('otp', $order->delivered_by);
        $this->assertSame('final', $order->status, 'a draft sale became a complete one');
        $this->assertNotNull($order->number);
        $this->assertSame($order->due ?? null, null);
        $this->assertGreaterThan(0, (float) $order->cash_collected);
    }

    public function test_a_wrong_code_does_not_deliver_and_five_wrong_ones_lock_it_and_tell_the_office(): void
    {
        $order = $this->assign($this->order());
        $wrong = $order->delivery_code === '111111' ? '222222' : '111111';

        foreach (range(1, 5) as $i) {
            $this->actingAs($this->rider)->post("/admin/deliveries/{$order->id}/confirm", ['code' => $wrong])->assertSessionHasErrors('delivery');
        }

        $this->actingAs($this->rider)->post("/admin/deliveries/{$order->id}/confirm", ['code' => $order->delivery_code])->assertSessionHasErrors('delivery');
        $this->assertNull($order->fresh()->delivered_at, 'locked even for the right one');
        $this->assertGreaterThan(0, Notice::where('kind', 'delivery_code_locked')->count());
    }

    public function test_a_photo_works_when_the_buyer_cannot_give_the_code(): void
    {
        $order = $this->assign($this->order());

        $this->actingAs($this->rider)->post("/admin/deliveries/{$order->id}/confirm", ['photo' => UploadedFile::fake()->image('parcel.jpg', 400, 400)])->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame('photo', $order->delivered_by);
        $this->assertNotNull($order->delivery_photo_path);
        Storage::assertExists($order->delivery_photo_path);
    }

    public function test_neither_a_code_nor_a_photo_is_no_delivery(): void
    {
        $order = $this->assign($this->order());

        $this->actingAs($this->rider)->post("/admin/deliveries/{$order->id}/confirm", [])->assertSessionHasErrors('code');
        $this->assertNull($order->fresh()->delivered_at);
    }

    public function test_a_delivered_order_cannot_be_delivered_again(): void
    {
        $order = $this->assign($this->order());
        $this->actingAs($this->rider)->post("/admin/deliveries/{$order->id}/confirm", ['code' => $order->delivery_code]);

        $this->actingAs($this->rider)->post("/admin/deliveries/{$order->id}/confirm", ['code' => $order->delivery_code])->assertSessionHasErrors('delivery');
        $this->actingAs($this->rider)->get('/admin/deliveries')->assertInertia(fn (Assert $p) => $p->has('groups', 0));
    }

    public function test_the_code_is_not_shown_to_the_buyer_after_delivery(): void
    {
        $order = $this->assign($this->order());
        $this->actingAs($this->rider)->post("/admin/deliveries/{$order->id}/confirm", ['code' => $order->delivery_code]);
        auth()->logout();

        $this->get("/orders/{$order->public_token}")->assertInertia(fn (Assert $p) => $p->where('order.code', null)->where('order.own_delivery', false)->where('order.delivered', true));
    }

    // ------------------------------------------------------------------ the money

    public function test_the_money_taken_is_kept_until_it_is_handed_in(): void
    {
        $a = $this->assign($this->order());
        $b = $this->assign($this->order('Gulshan'));
        $this->actingAs($this->rider)->post("/admin/deliveries/{$a->id}/confirm", ['code' => $a->delivery_code, 'collected' => 150]);
        $this->actingAs($this->rider)->post("/admin/deliveries/{$b->id}/confirm", ['code' => $b->delivery_code, 'collected' => 90.5]);

        $this->assertSame(240.5, app(Deliveries::class)->holding($this->rider));
        $this->actingAs($this->rider)->get('/admin/deliveries')->assertInertia(fn (Assert $p) => $p->where('holding', 240.5));
        $this->actingAs($this->staff)->get('/admin/delivery-cash')->assertInertia(fn (Assert $p) => $p->where('people.0.name', 'Rider One')->where('people.0.holding', 240.5)->where('people.0.orders', 2)->where('people.1.holding', 0));

        $this->actingAs($this->staff)->post("/admin/delivery-cash/{$this->rider->id}")->assertSessionHasNoErrors();
        $this->assertSame(0.0, app(Deliveries::class)->holding($this->rider));
    }

    public function test_the_money_defaults_to_what_was_due_when_nothing_is_typed(): void
    {
        $order = $this->assign($this->order());
        $due = app(Deliveries::class)->due($order);

        $this->actingAs($this->rider)->post("/admin/deliveries/{$order->id}/confirm", ['code' => $order->delivery_code]);

        $this->assertEqualsWithDelta($due, (float) $order->fresh()->cash_collected, 0.001);
    }

    public function test_one_person_cannot_hand_in_anothers_money_by_accident(): void
    {
        $order = $this->assign($this->order());
        $this->actingAs($this->rider)->post("/admin/deliveries/{$order->id}/confirm", ['code' => $order->delivery_code, 'collected' => 100]);

        $this->actingAs($this->staff)->post("/admin/delivery-cash/{$this->rider2->id}");

        $this->assertSame(100.0, app(Deliveries::class)->holding($this->rider));
        $this->actingAs($this->rider)->post("/admin/delivery-cash/{$this->rider->id}")->assertRedirect('/');
        $this->assertSame(100.0, app(Deliveries::class)->holding($this->rider));
    }

    // ------------------------------------------------------------------ rating

    private function delivered(): Invoice
    {
        $order = $this->assign($this->order());
        $this->actingAs($this->rider)->post("/admin/deliveries/{$order->id}/confirm", ['code' => $order->delivery_code]);
        auth()->logout();

        return $order->fresh();
    }

    public function test_the_buyer_rates_the_delivery_and_the_service_once_after_it_arrived(): void
    {
        $order = $this->delivered();
        $this->get("/orders/{$order->public_token}")->assertInertia(fn (Assert $p) => $p->where('order.can_rate', true));

        $this->post("/orders/{$order->public_token}/rate", ['delivery' => 5, 'service' => 4, 'comment' => 'Quick and polite'])->assertSessionHasNoErrors();
        $this->post("/orders/{$order->public_token}/rate", ['delivery' => 1, 'service' => 1])->assertSessionHasErrors('order');

        $r = DeliveryRating::firstOrFail();
        $this->assertSame($this->rider->id, $r->delivery_person_id);
        $this->assertSame($this->staff->id, $r->staff_id);
        $this->assertSame(0, Notice::where('kind', 'delivery_low_rating')->count());
        $this->get("/orders/{$order->public_token}")->assertInertia(fn (Assert $p) => $p->where('order.can_rate', false));
    }

    public function test_it_cannot_be_rated_before_it_arrives(): void
    {
        $order = $this->assign($this->order());

        $this->post("/orders/{$order->public_token}/rate", ['delivery' => 5, 'service' => 5])->assertSessionHasErrors('order');
        $this->post("/orders/{$order->public_token}/rate", ['delivery' => 6, 'service' => 5])->assertSessionHasErrors('delivery');
        $this->assertSame(0, DeliveryRating::count());
    }

    public function test_a_low_rating_comes_to_the_office_at_once(): void
    {
        $order = $this->delivered();

        $this->post("/orders/{$order->public_token}/rate", ['delivery' => 1, 'service' => 5])->assertSessionHasNoErrors();

        $this->assertGreaterThan(0, Notice::where('kind', 'delivery_low_rating')->count());
    }

    public function test_the_office_sees_averages_per_person_and_per_staff_and_the_buyer_sees_no_names(): void
    {
        $a = $this->delivered();
        $this->post("/orders/{$a->public_token}/rate", ['delivery' => 5, 'service' => 4]);
        $b = $this->delivered();
        $this->post("/orders/{$b->public_token}/rate", ['delivery' => 2, 'service' => 4, 'comment' => 'Late']);

        $this->actingAs($this->staff)->get('/admin/delivery-ratings')->assertInertia(fn (Assert $p) => $p
            ->where('people.0.name', 'Rider One')->where('people.0.average', 3.5)->where('people.0.count', 2)->where('staff.0.name', 'Office Staff')->where('staff.0.average', 4)
            ->has('low', 1)->where('low.0.comment', 'Late'));

        auth()->logout();
        $page = $this->get("/orders/{$a->public_token}")->viewData('page')['props'];
        $this->assertStringNotContainsString('Rider One', json_encode($page));
        $this->assertStringNotContainsString('Office Staff', json_encode($page));
    }

    public function test_the_ratings_page_is_for_the_office(): void
    {
        $this->actingAs($this->rider)->get('/admin/delivery-ratings')->assertRedirect('/');
        $this->actingAs(User::factory()->create(['account_type' => AccountType::Public]))->get('/admin/delivery-ratings')->assertRedirect('/');
    }
}
