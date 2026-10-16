<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Http\Middleware\SetLocale;
use App\Mail\StatusMessage;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Medicine;
use App\Models\Notice;
use App\Models\Setting;
use App\Models\StockSource;
use App\Models\Tier;
use App\Models\User;
use App\Services\Messages\Messenger;
use App\Services\Messages\SmsGateway;
use App\Services\Sales\PublicOrders;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 23: automatic status messages by inbox, email and SMS, controlled from the admin panel, urgent first. */
class MessagesTest extends TestCase
{
    use RefreshDatabase;

    private User $shop;

    private User $staff;

    private User $admin;

    private Medicine $napa;

    private Medicine $seclo;

    protected function setUp(): void
    {
        parent::setUp();
        $tier = Tier::create(['name' => 'A', 'discount_percent' => 10]);
        $this->shop = User::factory()->create(['account_type' => AccountType::Paikari, 'tier_id' => $tier->id, 'email' => 'karim@example.com', 'phone' => '01711111111']);
        $this->staff = User::factory()->create(['account_type' => AccountType::Staff]);
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $company = Company::create(['name' => 'Square']);
        $make = fn (string $name) => Medicine::create([
            'company_id' => $company->id, 'brand_name' => $name, 'strength' => '500 mg', 'form' => 'tablet', 'packaging_type' => 'box',
            'strips_per_box' => 3, 'pieces_per_strip' => 10, 'mrp_box' => 300, 'mrp_strip' => 100, 'mrp_piece' => 10, 'is_active' => true,
        ]);
        $this->napa = $make('Napa');
        $this->seclo = $make('Seclo');
        $stock = app(StockService::class);
        $stock->receive(StockSource::create(['name' => 'Direct', 'type' => 'company', 'default_percent' => 20]), [
            'invoice_date' => now()->toDateString(), 'lines' => [['medicine_id' => $this->napa->id, 'units' => 10], ['medicine_id' => $this->seclo->id, 'units' => 10]],
        ]);
        $stock->setMasterSync(true);
    }

    private function kinds(?User $user = null): array
    {
        return Notice::where('user_id', ($user ?? $this->shop)->id)->orderBy('id')->pluck('kind')->all();
    }

    private function place(array $items = ['napa' => 2]): Invoice
    {
        $this->actingAs($this->shop);
        foreach ($items as $name => $qty) {
            $this->post('/paikari/cart', ['medicine_id' => $this->{$name}->id, 'unit' => 'box', 'quantity' => $qty]);
        }
        $this->post('/paikari/order', ['delivery_mode' => 'collect'])->assertSessionHasNoErrors();

        return Invoice::latest('id')->firstOrFail();
    }

    private function verify(Invoice $order, string $status = 'full', ?float $qty = null, int $line = 0): void
    {
        $l = $order->lines()->orderBy('id')->get()[$line];
        $this->actingAs($this->staff)->post("/admin/orders/{$order->id}/lines/{$l->id}/verify", ['status' => $status, 'quantity' => $qty])->assertSessionHasNoErrors();
    }

    public function test_an_order_moves_through_its_messages_and_each_goes_to_the_inbox_once(): void
    {
        $order = $this->place(['napa' => 2, 'seclo' => 2]);
        $this->assertSame(['order_received'], $this->kinds());

        $this->verify($order, 'full', null, 0);
        $this->verify($order, 'full', null, 1);
        $this->assertSame(['order_received', 'order_picking'], $this->kinds());

        $this->actingAs($this->staff)->post("/admin/orders/{$order->id}/complete")->assertSessionHasNoErrors();
        $this->post("/admin/counter/{$order->id}/finalise")->assertSessionHasNoErrors();

        $this->assertSame(['order_received', 'order_picking', 'order_checked', 'order_done'], $this->kinds());
    }

    public function test_a_partial_supply_sends_an_urgent_message_that_lists_what_was_short(): void
    {
        $order = $this->place(['napa' => 4, 'seclo' => 2]);
        $this->verify($order, 'partial', 1, 0);
        $this->verify($order, 'unavailable', null, 1);
        $this->actingAs($this->staff)->post("/admin/orders/{$order->id}/complete");

        $partial = Notice::where('kind', 'order_partial')->firstOrFail();
        $this->assertSame('urgent', $partial->importance);
        $this->assertSame('Napa 500 mg, Seclo 500 mg', json_decode($partial->body, true)['items']);
        $this->assertSame('normal', Notice::where('kind', 'order_checked')->firstOrFail()->importance);
    }

    public function test_a_guest_gets_no_message_and_nothing_breaks(): void
    {
        $this->post('/cart', ['medicine_id' => $this->napa->id, 'unit' => 'strip', 'quantity' => 1]);
        $this->post('/checkout', ['name' => 'Hasan', 'phone' => '01711000000', 'district' => 'Dhaka', 'area' => 'Mirpur', 'address' => 'House 5', 'delivery_mode' => 'delivery'])->assertSessionHasNoErrors();
        $order = Invoice::firstOrFail();
        $this->verify($order);
        $this->actingAs($this->staff)->post("/admin/orders/{$order->id}/complete")->assertSessionHasNoErrors();

        $this->assertSame(0, Notice::count());
    }

    public function test_a_counter_sale_to_a_shop_is_not_an_order_message(): void
    {
        $this->actingAs($this->staff)->post('/admin/counter', ['kind' => 'paikari', 'customer_id' => $this->shop->id]);
        $draft = Invoice::firstOrFail();
        $this->post("/admin/counter/{$draft->id}/lines", ['medicine_id' => $this->napa->id, 'unit' => 'box', 'quantity' => 1]);
        $this->post("/admin/counter/{$draft->id}/finalise")->assertSessionHasNoErrors();

        $this->assertSame(0, Notice::where('user_id', $this->shop->id)->count());
    }

    public function test_a_cancellation_by_us_tells_the_buyer_why_and_by_the_buyer_does_not(): void
    {
        $asked = $this->place();
        $asked->update(['cancel_requested_at' => now()]);
        $this->actingAs($this->staff)->post("/admin/orders/{$asked->id}/cancel-decision", ['approve' => true])->assertSessionHasNoErrors();
        $cancelled = Notice::where('kind', 'order_cancelled')->firstOrFail();
        $this->assertSame('urgent', $cancelled->importance);
        $this->assertNotSame('', json_decode($cancelled->body, true)['reason']);

        Notice::query()->delete();
        $mine = $this->place();
        $this->actingAs($this->shop)->post("/paikari/orders/{$mine->id}/cancel")->assertSessionHasNoErrors();
        $this->assertNotContains('order_cancelled', $this->kinds());
    }

    public function test_an_order_with_nothing_available_is_cancelled_with_a_message(): void
    {
        $order = $this->place();
        $this->verify($order, 'unavailable');
        $this->actingAs($this->staff)->post("/admin/orders/{$order->id}/complete");

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertContains('order_cancelled', $this->kinds());
    }

    public function test_an_order_nobody_answered_is_cancelled_and_the_account_holder_told(): void
    {
        $order = $this->place();
        $order->update(['placed_at' => now()->subDays(5), 'kind' => 'retail']);

        $this->assertSame(1, app(PublicOrders::class)->cancelStale());
        $this->assertContains('order_cancelled', $this->kinds());
    }

    public function test_by_default_only_the_inbox_is_used(): void
    {
        Mail::fake();
        $this->place();

        Mail::assertNothingSent();
        $this->assertSame(['inbox'], app(Messenger::class)->channelsFor('order_received'));
    }

    public function test_a_message_can_also_go_by_email_but_only_to_a_real_address(): void
    {
        Mail::fake();
        Setting::write('messages.channels', ['order_received' => ['inbox', 'email']]);

        $this->place();
        Mail::assertQueued(StatusMessage::class, fn (StatusMessage $m) => $m->hasTo('karim@example.com') && $m->link !== null);

        Mail::fake();
        $this->shop->forceFill(['email' => '01711111111@phone.invalid'])->save();
        $this->place();
        Mail::assertNothingSent();
    }

    public function test_a_message_can_go_by_sms_and_a_gateway_that_fails_never_stops_the_order(): void
    {
        $sent = [];
        $this->app->instance(SmsGateway::class, new class($sent) implements SmsGateway
        {
            public function __construct(public array &$sent) {}

            public function send(string $phone, string $text): void
            {
                $this->sent[] = [$phone, $text];
            }
        });
        Setting::write('messages.channels', ['order_received' => ['inbox', 'sms']]);
        $this->place();
        $this->assertSame('01711111111', $sent[0][0]);
        $this->assertNotSame('', $sent[0][1]);

        $this->app->instance(SmsGateway::class, new class implements SmsGateway
        {
            public function send(string $phone, string $text): void
            {
                throw new \RuntimeException('provider down');
            }
        });
        $order = $this->place();
        $this->assertSame('placed', $order->status);
        $this->assertSame(2, Notice::where('kind', 'order_received')->count());
    }

    public function test_the_words_go_out_in_bangla_whoever_clicked(): void
    {
        $text = app(Messenger::class)->text('order_checked', [], SetLocale::DEFAULT);

        $this->assertNotSame('Your order is checked and ready.', $text);
        $this->assertSame('Your order is checked and ready.', app(Messenger::class)->text('order_checked', [], 'en'));
        $this->assertStringContainsString('Napa', app(Messenger::class)->text('preorder_accepted', ['name' => 'Napa', 'days' => 3], 'en'));
    }

    public function test_the_inbox_shows_urgent_first_then_the_newest_and_counts_the_unread(): void
    {
        $m = app(Messenger::class);
        $m->send($this->shop, 'order_received');
        $m->send($this->shop, 'order_cancelled', ['reason' => 'x'], '/paikari');
        $m->send($this->shop, 'order_checked');
        $m->send($this->shop, 'order_picking');

        $this->actingAs($this->shop)->get('/inbox')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('inbox')->has('messages', 4)
            ->where('messages.0.kind', 'order_cancelled')->where('messages.0.link', '/paikari')
            ->where('messages.1.kind', 'order_checked')->where('messages.2.kind', 'order_received')->where('messages.3.kind', 'order_picking')
            ->where('auth.inbox', 4));

        $this->post('/inbox/read-all');
        $this->get('/inbox')->assertInertia(fn (Assert $p) => $p->where('auth.inbox', 0)->where('messages.0.read', true));
    }

    public function test_the_inbox_is_for_customers_only_and_one_persons_messages_are_private(): void
    {
        $m = app(Messenger::class);
        $other = User::factory()->create(['account_type' => AccountType::Public]);
        $m->send($other, 'order_received');

        $this->get('/inbox')->assertRedirect('/login');
        $this->actingAs($this->shop)->get('/inbox')->assertInertia(fn (Assert $p) => $p->has('messages', 0));
        $this->actingAs($this->staff)->get('/inbox')->assertRedirect('/');
        $this->actingAs($this->admin)->get('/inbox')->assertRedirect('/');
        $this->actingAs($this->staff)->get('/admin/counter')->assertInertia(fn (Assert $p) => $p->where('auth.inbox', null));
    }

    public function test_a_kind_takes_its_importance_from_the_config(): void
    {
        $this->assertSame('urgent', Notice::create(['user_id' => $this->shop->id, 'kind' => 'preorder_cancelled', 'body' => '{}'])->importance);
        $this->assertSame('low', Notice::create(['user_id' => $this->shop->id, 'kind' => 'tier_up', 'body' => '{}'])->importance);
        $this->assertSame('normal', Notice::create(['user_id' => $this->shop->id, 'kind' => 'something_else', 'body' => '{}'])->importance);
    }

    public function test_the_owner_chooses_the_channels_the_inbox_stays_on_and_push_is_not_available(): void
    {
        $this->actingAs($this->admin)->get('/admin/messages')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('admin/messages')->has('kinds', 79)->where('available', ['inbox', 'email', 'sms']));

        $this->put('/admin/messages', ['channels' => ['order_cancelled' => ['email', 'sms'], 'order_done' => []]])->assertSessionHasNoErrors();
        $this->assertSame(['inbox', 'email', 'sms'], app(Messenger::class)->channelsFor('order_cancelled'));
        $this->assertSame(['inbox'], app(Messenger::class)->channelsFor('order_done'));
        $this->assertSame(1, ActivityLog::where('action', 'messages.channels')->count());

        $this->put('/admin/messages', ['channels' => ['order_cancelled' => ['push']]])->assertSessionHasErrors('channels.order_cancelled.0');
        $this->put('/admin/messages', ['channels' => ['order_cancelled' => ['fax']]])->assertSessionHasErrors();
    }

    public function test_only_the_owner_side_reaches_the_channel_screen(): void
    {
        $this->actingAs($this->staff)->get('/admin/messages')->assertRedirect('/');
        $this->put('/admin/messages', ['channels' => []])->assertRedirect('/');
        $this->actingAs($this->shop)->get('/admin/messages')->assertRedirect('/');
        $this->assertNull(Setting::find('messages.channels'));
    }
}
