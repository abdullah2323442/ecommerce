<?php

namespace Tests\Feature\Sales;

use App\Enums\AccountType;
use App\Jobs\SendSms;
use App\Models\Notice;
use App\Models\User;
use App\Services\Integrations\Integrations;
use App\Services\Integrations\SafeUrl;
use App\Services\Messages\ConfiguredSmsGateway;
use App\Services\Messages\Messenger;
use App\Services\Messages\QueuedSmsGateway;
use App\Services\Messages\SmsGateway;
use App\Services\Security\PhoneVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Spec ch 28: many orders at once in a festival. A slow text service must not hold up checkout, so texts follow from a queue. */
class QueuedMessagesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        SafeUrl::resolveUsing(fn () => ['8.8.8.8']);
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
    }

    protected function tearDown(): void
    {
        SafeUrl::resolveUsing(null);
        parent::tearDown();
    }

    private function liveText(): void
    {
        app(Integrations::class)->save('sms:http', ['url' => 'https://sms.example.com/send', 'method' => 'POST', 'to_param' => 'to', 'message_param' => 'message', 'success_text' => 'ok'], true, 'live', [], $this->admin);
    }

    public function test_the_site_only_queues_a_text_and_does_not_wait_for_the_provider(): void
    {
        Queue::fake();
        Http::fake();
        $this->liveText();

        app(SmsGateway::class)->send('01712345678', 'Hello');

        Queue::assertPushed(SendSms::class, fn (SendSms $j) => $j->phone === '01712345678' && $j->text === 'Hello');
        Http::assertNothingSent();
        $this->assertInstanceOf(QueuedSmsGateway::class, app(SmsGateway::class));
    }

    public function test_the_job_does_the_real_sending_later(): void
    {
        $this->liveText();
        Http::fake(['sms.example.com/*' => Http::response('ok', 200)]);

        (new SendSms('01712345678', 'Hello'))->handle(app(ConfiguredSmsGateway::class));

        Http::assertSent(fn ($r) => $r['to'] === '01712345678' && $r['message'] === 'Hello');
    }

    public function test_a_provider_that_is_down_makes_the_job_fail_so_it_is_tried_again(): void
    {
        $this->liveText();
        Http::fake(['sms.example.com/*' => Http::response('no', 500)]);

        $this->expectException(\RuntimeException::class);
        (new SendSms('01712345678', 'Hello'))->handle(app(ConfiguredSmsGateway::class));
    }

    public function test_it_is_tried_four_times_with_longer_waits_and_a_time_limit(): void
    {
        $job = new SendSms('01712345678', 'Hello');

        $this->assertSame(4, $job->tries);
        $this->assertSame([30, 120, 600], $job->backoff());
        $this->assertLessThanOrEqual(30, $job->timeout);
    }

    public function test_giving_up_is_logged_without_the_whole_number_or_the_message(): void
    {
        Log::spy();

        (new SendSms('01712345678', 'Your code is 424242'))->failed(new \RuntimeException('The service did not accept it.'));

        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($m, $ctx) => $ctx['to'] === '*******678' && ! str_contains(json_encode($ctx), '0171234') && ! str_contains(json_encode($ctx), '424242'));
    }

    public function test_an_order_is_placed_even_when_the_text_service_is_down(): void
    {
        $this->liveText();
        Http::fake(['sms.example.com/*' => Http::response('no', 500)]);
        Queue::fake();

        $user = User::factory()->create(['phone' => '01712345678']);
        app(Messenger::class)->send($user, 'order_received', ['number' => 'ORD1']);

        $this->assertSame(1, Notice::where('user_id', $user->id)->count());
    }

    public function test_a_code_someone_is_waiting_for_is_not_made_to_wait_in_the_queue(): void
    {
        Queue::fake();
        $sent = [];
        $this->app->instance(ConfiguredSmsGateway::class, new class($sent) implements SmsGateway
        {
            public function __construct(private array &$out) {}

            public function send(string $phone, string $text): void
            {
                $this->out[] = $phone;
            }
        });

        $user = User::factory()->create(['account_type' => AccountType::Public]);
        app(PhoneVerifier::class)->send($user, '01712345678');

        $this->assertSame(['01712345678'], $sent);
        Queue::assertNotPushed(SendSms::class);
    }

    public function test_the_queue_is_emptied_every_minute_where_no_worker_is_kept_running(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('queue:work')->assertSuccessful();
    }
}
