<?php

namespace Tests\Feature\Push;

use App\Enums\AccountType;
use App\Http\Middleware\SetLocale;
use App\Models\Integration;
use App\Models\PushDevice;
use App\Models\Setting;
use App\Models\User;
use App\Services\Integrations\Integrations;
use App\Services\Integrations\SafeUrl;
use App\Services\Messages\Messenger;
use App\Services\Push\Notifications;
use App\Services\Push\PushSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 23, push: notifications to a phone or browser that said yes, by the Web Push standard, with signing keys the site makes itself. */
class PushTest extends TestCase
{
    use RefreshDatabase;

    private const FCM = 'https://fcm.googleapis.com/fcm/send/abc123';

    private User $admin;

    private User $buyer;

    /** @var array<int, array{0: array<string, string>, 1: array<string, mixed>}> */
    private array $sent = [];

    /** What the fake push service answers: ok, gone or a failure. */
    private string $answer = 'ok';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCookie(SetLocale::COOKIE, 'en');
        SafeUrl::resolveUsing(fn (string $host) => $host === 'localhost' ? ['127.0.0.1'] : ['93.184.216.34']);
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
        $this->buyer = User::factory()->create(['account_type' => AccountType::Public]);
        $this->sent = [];
        $this->app->bind(PushSender::class, fn () => new class($this) implements PushSender
        {
            public function __construct(private PushTest $test) {}

            public function send(array $subscription, string $payload, array $vapid): array
            {
                return $this->test->record($subscription, $payload);
            }
        });
    }

    protected function tearDown(): void
    {
        SafeUrl::resolveUsing(null);
        parent::tearDown();
    }

    /** @internal called by the fake sender */
    public function record(array $subscription, string $payload): array
    {
        if ($this->answer === 'boom') {
            throw new \RuntimeException('network');
        }

        $this->sent[] = [$subscription, json_decode($payload, true)];

        return ['ok' => $this->answer === 'ok', 'gone' => $this->answer === 'gone'];
    }

    private function on(string $mode = 'live'): void
    {
        app(Integrations::class)->save('push:webpush', ['subject' => 'mailto:owner@example.com'], true, $mode, [], $this->admin);
    }

    private function sub(string $endpoint = self::FCM): array
    {
        return ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'BPublicKeyOfTheBrowser', 'auth' => 'AuthSecret1']];
    }

    // ------------------------------------------------------------------ switching it on

    public function test_switching_it_on_makes_the_signing_keys_and_nothing_is_pasted(): void
    {
        $this->on();

        $row = Integration::where('key', 'push:webpush')->firstOrFail();
        $public = $row->settings['public_key'];
        $private = $row->credentials['private_key'];

        $this->assertGreaterThan(60, strlen($public));
        $this->assertGreaterThan(30, strlen($private));
        $this->assertTrue(app(Notifications::class)->enabled());
        $this->assertSame($public, app(Notifications::class)->publicKey());
        $this->assertTrue(app(Integrations::class)->test('push:webpush', [], $this->admin)['ok']);
    }

    public function test_saving_again_keeps_the_same_keys_so_nobody_has_to_say_yes_twice(): void
    {
        $this->on();
        $first = Integration::where('key', 'push:webpush')->firstOrFail()->settings['public_key'];

        app(Integrations::class)->save('push:webpush', ['subject' => 'mailto:other@example.com'], true, 'live', [], $this->admin);

        $this->assertSame($first, Integration::where('key', 'push:webpush')->firstOrFail()->settings['public_key']);
    }

    public function test_clearing_the_private_key_makes_a_fresh_pair(): void
    {
        $this->on();
        $first = Integration::where('key', 'push:webpush')->firstOrFail()->settings['public_key'];

        app(Integrations::class)->save('push:webpush', ['public_key' => ''], true, 'live', ['private_key'], $this->admin);

        $this->assertNotSame($first, Integration::where('key', 'push:webpush')->firstOrFail()->settings['public_key']);
    }

    public function test_a_pair_that_does_not_match_is_caught_by_the_test_button(): void
    {
        $this->on();
        Integration::where('key', 'push:webpush')->update(['settings' => ['subject' => 'mailto:owner@example.com', 'public_key' => 'not-a-key']]);

        $this->assertFalse(app(Integrations::class)->test('push:webpush', [], $this->admin)['ok']);
    }

    public function test_it_is_off_until_switched_on_and_live(): void
    {
        $this->actingAs($this->buyer)->get('/inbox')->assertInertia(fn (Assert $p) => $p->where('push.key', null));
        $this->postJson('/push/subscribe', $this->sub())->assertStatus(422);

        $this->on('test');
        $this->assertFalse(app(Notifications::class)->enabled());

        $this->on();
        $this->get('/inbox')->assertInertia(fn (Assert $p) => $p->where('push.key', app(Notifications::class)->publicKey()));
    }

    public function test_the_owner_can_choose_push_for_a_message_only_once_it_is_on(): void
    {
        $this->actingAs($this->admin)->get('/admin/messages')->assertInertia(fn (Assert $p) => $p->where('available', ['inbox', 'email', 'sms']));
        $this->put('/admin/messages', ['channels' => ['order_done' => ['push']]])->assertSessionHasErrors();

        $this->on();

        $this->get('/admin/messages')->assertInertia(fn (Assert $p) => $p->where('available', ['inbox', 'email', 'sms', 'push']));
        $this->put('/admin/messages', ['channels' => ['order_done' => ['push']]])->assertSessionHasNoErrors();
        $this->assertContains('push', app(Messenger::class)->channelsFor('order_done'));
    }

    // ------------------------------------------------------------------ devices

    public function test_a_signed_in_person_says_yes_once_and_the_same_device_is_one_row(): void
    {
        $this->on();

        $this->actingAs($this->buyer)->postJson('/push/subscribe', $this->sub())->assertOk();
        $this->postJson('/push/subscribe', $this->sub())->assertOk();

        $this->assertSame(1, PushDevice::count());
        $this->assertSame($this->buyer->id, PushDevice::firstOrFail()->user_id);
    }

    public function test_a_guest_cannot_subscribe(): void
    {
        $this->on();

        $this->postJson('/push/subscribe', $this->sub())->assertUnauthorized();
    }

    public function test_only_the_browsers_own_push_services_are_accepted(): void
    {
        $this->on();
        $this->actingAs($this->buyer);

        foreach (['http://fcm.googleapis.com/x', 'https://evil.example/hook', 'https://127.0.0.1/x', 'https://169.254.169.254/latest', 'https://notgoogleapis.com/x', 'https://fcm.googleapis.com.evil.example/x', 'ftp://fcm.googleapis.com/x'] as $endpoint) {
            $this->postJson('/push/subscribe', $this->sub($endpoint))->assertStatus(422);
        }

        foreach (['https://updates.push.services.mozilla.com/wpush/v2/abc', 'https://web.push.apple.com/abc', 'https://fcm.googleapis.com/fcm/send/zzz'] as $endpoint) {
            $this->postJson('/push/subscribe', $this->sub($endpoint))->assertOk();
        }

        $this->assertSame(3, PushDevice::count());
    }

    public function test_the_bad_shapes_are_refused(): void
    {
        $this->on();
        $this->actingAs($this->buyer);

        $this->postJson('/push/subscribe', ['endpoint' => self::FCM])->assertStatus(422);
        $this->postJson('/push/subscribe', ['endpoint' => self::FCM, 'keys' => ['p256dh' => str_repeat('x', 300), 'auth' => 'a']])->assertStatus(422);
        $this->assertSame(0, PushDevice::count());
    }

    public function test_a_person_keeps_only_their_five_newest_devices(): void
    {
        $this->on();
        $this->actingAs($this->buyer);

        foreach (range(1, 7) as $i) {
            $this->postJson('/push/subscribe', $this->sub("https://fcm.googleapis.com/fcm/send/dev{$i}"))->assertOk();
        }

        $this->assertSame(5, PushDevice::count());
        $this->assertSame(0, PushDevice::where('endpoint', 'like', '%dev1')->count());
        $this->assertSame(1, PushDevice::where('endpoint', 'like', '%dev7')->count());
    }

    public function test_turning_it_off_removes_that_device_and_only_the_persons_own(): void
    {
        $this->on();
        $other = User::factory()->create(['account_type' => AccountType::Public]);
        $this->actingAs($this->buyer)->postJson('/push/subscribe', $this->sub('https://fcm.googleapis.com/fcm/send/mine'))->assertOk();
        $this->actingAs($other)->postJson('/push/subscribe', $this->sub('https://fcm.googleapis.com/fcm/send/theirs'))->assertOk();

        $this->actingAs($this->buyer)->deleteJson('/push/subscribe', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/theirs'])->assertOk();
        $this->assertSame(2, PushDevice::count());

        $this->deleteJson('/push/subscribe', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/mine'])->assertOk();
        $this->assertSame(['theirs'], PushDevice::pluck('endpoint')->map(fn ($e) => basename($e))->all());
    }

    // ------------------------------------------------------------------ sending

    private function pushForOrderDone(): void
    {
        Setting::write('messages.channels', ['order_done' => ['inbox', 'push']]);
    }

    public function test_a_message_set_to_go_by_push_reaches_every_device_with_its_words_and_page(): void
    {
        $this->on();
        $this->pushForOrderDone();
        $this->actingAs($this->buyer)->postJson('/push/subscribe', $this->sub('https://fcm.googleapis.com/fcm/send/a'));
        $this->postJson('/push/subscribe', $this->sub('https://web.push.apple.com/b'));

        app(Messenger::class)->send($this->buyer, 'order_done', ['name' => 'M2026-000007'], '/my-orders');

        $this->assertCount(2, $this->sent);
        $payload = $this->sent[0][1];
        $this->assertSame(mb_substr(app(Messenger::class)->text('order_done', ['name' => 'M2026-000007'], 'bn'), 0, 180), $payload['body']);
        $this->assertSame('/my-orders', $payload['url']);
        $this->assertNotSame('', $payload['title']);
        $this->assertNotNull(PushDevice::first()->last_sent_at);
    }

    public function test_nothing_goes_when_push_is_not_chosen_for_that_message_or_there_is_no_device(): void
    {
        $this->on();
        $this->actingAs($this->buyer)->postJson('/push/subscribe', $this->sub());

        app(Messenger::class)->send($this->buyer, 'order_done', ['name' => 'M1'], '/my-orders'); // push is not set for this one
        $this->pushForOrderDone();
        app(Messenger::class)->send(User::factory()->create(['account_type' => AccountType::Public]), 'order_done', ['name' => 'M2']); // nobody to send to

        $this->assertSame([], $this->sent);
    }

    public function test_nothing_goes_while_the_service_is_off_or_in_test_mode(): void
    {
        $this->pushForOrderDone();
        $this->on();
        $this->actingAs($this->buyer)->postJson('/push/subscribe', $this->sub());
        $this->on('test');

        app(Messenger::class)->send($this->buyer, 'order_done', ['name' => 'M1']);

        $this->assertSame([], $this->sent);
    }

    public function test_a_device_the_browser_dropped_is_forgotten_and_a_failure_never_breaks_the_message(): void
    {
        $this->on();
        $this->pushForOrderDone();
        $this->actingAs($this->buyer)->postJson('/push/subscribe', $this->sub());

        $this->answer = 'boom';
        app(Messenger::class)->send($this->buyer, 'order_done', ['name' => 'M1']);
        $this->assertSame(1, PushDevice::count());
        $this->assertDatabaseHas('notices', ['user_id' => $this->buyer->id, 'kind' => 'order_done']); // the inbox still got it

        $this->answer = 'gone';
        app(Messenger::class)->send($this->buyer, 'order_done', ['name' => 'M2']);
        $this->assertSame(0, PushDevice::count());
    }

    public function test_a_notification_only_ever_opens_a_page_of_this_site(): void
    {
        $this->on();
        $this->actingAs($this->buyer)->postJson('/push/subscribe', $this->sub());

        foreach (['https://evil.example/x', '//evil.example', '/\\evil.example', 'javascript:alert(1)', null] as $link) {
            app(Notifications::class)->deliver($this->buyer->id, 'T', 'B', $link);
        }

        $this->assertSame(['/inbox'], array_unique(array_column(array_column($this->sent, 1), 'url')));
    }

    public function test_the_worker_shows_notifications_and_opens_their_page(): void
    {
        $js = $this->get('/sw.js')->assertOk()->getContent();

        $this->assertStringContainsString("addEventListener('push'", $js);
        $this->assertStringContainsString("addEventListener('notificationclick'", $js);
    }
}
