<?php

namespace Tests\Feature\Admin;

use App\Enums\AccountType;
use App\Models\ActivityLog;
use App\Models\Integration;
use App\Models\User;
use App\Services\Integrations\Integrations;
use App\Services\Messages\QueuedSmsGateway;
use App\Services\Messages\SmsGateway;
use App\Services\Stock\StockException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

/** The outside services are connected from the admin panel: secrets encrypted and never shown again, a test button, and nothing real happens in test mode. */
class ConnectionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin]);
    }

    private function save(string $key, array $fields = [], bool $enabled = false, string $mode = 'test', array $clear = [])
    {
        return $this->actingAs($this->admin)->put("/admin/connections/{$key}", ['fields' => $fields, 'enabled' => $enabled ? 1 : 0, 'mode' => $mode, 'clear' => $clear]);
    }

    private function sms(array $more = []): array
    {
        return $more + ['url' => 'https://8.8.8.8/send', 'method' => 'POST', 'to_param' => 'to', 'message_param' => 'message', 'extra' => 'api_key=SECRET-KEY-9876&senderid=Shop', 'success_text' => ''];
    }

    private function provider(string $key): array
    {
        foreach (app(Integrations::class)->overview() as $type) {
            foreach ($type['providers'] as $p) {
                if ($p['key'] === $key) {
                    return $p;
                }
            }
        }

        return [];
    }

    public function test_the_page_lists_every_kind_of_service_and_says_which_are_built(): void
    {
        $this->actingAs($this->admin)->get('/admin/connections')->assertInertia(fn (Assert $p) => $p->component('admin/connections')->has('types', 8)
            ->where('types.0.type', 'sms')->where('types.0.providers.0.ready', true)->where('types.2.providers.0.ready', false));
    }

    public function test_a_secret_is_encrypted_in_the_database_and_never_sent_to_a_page(): void
    {
        $this->save('sms:http', $this->sms())->assertSessionHasNoErrors();

        $raw = DB::table('integrations')->where('key', 'sms:http')->value('credentials');
        $this->assertStringNotContainsString('SECRET-KEY-9876', (string) $raw);
        $this->assertNotNull($raw);
        $this->assertSame('api_key=SECRET-KEY-9876&senderid=Shop', Integration::where('key', 'sms:http')->firstOrFail()->credentials['extra'], 'the app can read it back');

        $page = $this->actingAs($this->admin)->get('/admin/connections')->viewData('page')['props'];
        $this->assertStringNotContainsString('SECRET-KEY-9876', json_encode($page));
        $extra = collect($this->provider('sms:http')['fields'])->firstWhere('name', 'extra');
        $this->assertTrue($extra['set']);
        $this->assertNull($extra['value']);
        $this->assertSame('••••Shop', $extra['hint']);
    }

    public function test_leaving_a_secret_empty_keeps_it_and_clearing_removes_it(): void
    {
        $this->save('sms:http', $this->sms());

        $this->save('sms:http', $this->sms(['extra' => '', 'success_text' => 'OK']))->assertSessionHasNoErrors();
        $row = Integration::where('key', 'sms:http')->firstOrFail();
        $this->assertSame('api_key=SECRET-KEY-9876&senderid=Shop', $row->credentials['extra']);
        $this->assertSame('OK', $row->settings['success_text']);

        $this->save('sms:http', $this->sms(['extra' => '']), clear: ['extra']);
        $this->assertArrayNotHasKey('extra', (array) Integration::where('key', 'sms:http')->firstOrFail()->credentials);
    }

    public function test_a_service_that_is_not_built_yet_can_keep_its_keys_but_not_be_switched_on(): void
    {
        $keys = ['app_key' => 'key-123', 'app_secret' => 'sec-456', 'username' => 'shop', 'password' => 'pw-789'];
        $this->save('payment:bkash', $keys)->assertSessionHasNoErrors();
        $this->assertSame('sec-456', Integration::where('key', 'payment:bkash')->firstOrFail()->credentials['app_secret']);

        $this->save('payment:bkash', $keys, enabled: true)->assertSessionHasErrors('connection');
        $this->assertFalse(Integration::where('key', 'payment:bkash')->firstOrFail()->enabled);
        $this->actingAs($this->admin)->post('/admin/connections/payment:bkash/test')->assertSessionHasErrors('connection');
    }

    public function test_switching_on_needs_the_required_fields_and_an_https_address(): void
    {
        $this->save('sms:http', $this->sms(['url' => '']), enabled: true)->assertSessionHasErrors('connection');
        $this->save('sms:http', $this->sms(['url' => 'http://plain.example.com/send']), enabled: true)->assertSessionHasErrors('connection');
        $this->save('sms:http', $this->sms(['method' => 'DELETE']), enabled: true)->assertSessionHasErrors('connection');
        $this->assertFalse((bool) Integration::where('key', 'sms:http')->value('enabled'));

        $this->save('sms:http', $this->sms(), enabled: true, mode: 'live')->assertSessionHasNoErrors();
        $this->assertTrue(Integration::where('key', 'sms:http')->firstOrFail()->enabled);
    }

    public function test_unknown_services_and_modes_are_refused(): void
    {
        $this->save('sms:nothing', [])->assertSessionHasErrors('connection');
        $this->save('sms:http', $this->sms(), mode: 'maybe')->assertSessionHasErrors('mode');
        $this->actingAs($this->admin)->put('/admin/connections/BAD KEY', ['enabled' => 0, 'mode' => 'test'])->assertNotFound();
    }

    public function test_only_one_text_service_is_on_at_a_time(): void
    {
        config(['integrations.providers.sms:alt' => array_merge(config('integrations.providers.sms:http'), ['name' => 'Alt'])]);
        $this->save('sms:http', $this->sms(), enabled: true);
        $this->save('sms:alt', $this->sms(), enabled: true)->assertSessionHasNoErrors();

        $this->assertFalse(Integration::where('key', 'sms:http')->firstOrFail()->enabled);
        $this->assertTrue(Integration::where('key', 'sms:alt')->firstOrFail()->enabled);
    }

    public function test_only_people_who_run_the_accounts_can_see_or_change_a_connection(): void
    {
        $staff = User::factory()->create(['account_type' => AccountType::Staff]);
        $buyer = User::factory()->create(['account_type' => AccountType::Public]);

        $this->actingAs($staff)->get('/admin/connections')->assertRedirect('/');
        $this->actingAs($staff)->put('/admin/connections/sms:http', ['fields' => $this->sms(), 'enabled' => 1, 'mode' => 'live'])->assertRedirect('/');
        $this->actingAs($buyer)->post('/admin/connections/sms:http/test', ['to' => '01712345678'])->assertRedirect('/');
        $this->get('/admin/connections')->assertRedirect();
        $this->assertSame(0, Integration::count());
    }

    public function test_the_log_names_the_fields_changed_but_never_a_value(): void
    {
        $this->save('sms:http', $this->sms(), enabled: true);

        $log = ActivityLog::where('action', 'integration.saved')->firstOrFail();

        $this->assertContains('extra', $log->changes['fields']);
        $this->assertStringNotContainsString('SECRET-KEY-9876', json_encode($log->toArray()));
        $this->assertStringNotContainsString('8.8.8.8', json_encode($log->toArray()));
    }

    // ------------------------------------------------------------------ the text gateway

    public function test_with_no_service_a_text_is_only_written_to_the_log(): void
    {
        Http::fake();
        Log::spy();

        app(SmsGateway::class)->send('01712345678', 'Your code is 123456');

        Http::assertNothingSent();
        Log::shouldHaveReceived('info')->once();
        $this->assertInstanceOf(QueuedSmsGateway::class, app(SmsGateway::class));
    }

    public function test_a_service_in_test_mode_sends_nothing_real(): void
    {
        Http::fake();
        $this->save('sms:http', $this->sms(), enabled: true, mode: 'test');

        app(SmsGateway::class)->send('01712345678', 'Your code is 123456');

        Http::assertNothingSent();
    }

    public function test_a_live_service_sends_with_the_names_and_the_key_the_owner_set(): void
    {
        Http::fake(['https://8.8.8.8/*' => Http::response('OK', 200)]);
        $this->save('sms:http', $this->sms(['to_param' => 'msisdn', 'message_param' => 'text']), enabled: true, mode: 'live');

        app(SmsGateway::class)->send('01712345678', 'Your code is 123456');

        Http::assertSent(fn ($r) => $r->url() === 'https://8.8.8.8/send' && $r['msisdn'] === '01712345678' && $r['text'] === 'Your code is 123456' && $r['api_key'] === 'SECRET-KEY-9876' && $r['senderid'] === 'Shop');
    }

    public function test_get_sends_the_fields_in_the_address(): void
    {
        Http::fake(['https://8.8.8.8/*' => Http::response('OK', 200)]);
        $this->save('sms:http', $this->sms(['method' => 'GET']), enabled: true, mode: 'live');

        app(SmsGateway::class)->send('01712345678', 'Hi');

        Http::assertSent(fn ($r) => $r->method() === 'GET' && str_contains($r->url(), 'to=01712345678') && str_contains($r->url(), 'api_key=SECRET-KEY-9876'));
    }

    public function test_a_failing_live_service_is_an_error_the_caller_can_see(): void
    {
        Http::fake(['https://8.8.8.8/*' => Http::response('no', 500)]);
        $this->save('sms:http', $this->sms(), enabled: true, mode: 'live');

        $this->expectException(RuntimeException::class);
        app(SmsGateway::class)->send('01712345678', 'Hi');
    }

    public function test_the_expected_word_decides_whether_it_worked(): void
    {
        Http::fake(['https://8.8.8.8/*' => Http::response('{"status":"failed"}', 200)]);
        $this->save('sms:http', $this->sms(['success_text' => 'success']), enabled: true, mode: 'live');

        $this->actingAs($this->admin)->post('/admin/connections/sms:http/test', ['to' => '01712345678'])->assertSessionHasNoErrors();
        $row = Integration::where('key', 'sms:http')->firstOrFail();

        $this->assertFalse($row->test_ok);
        $this->assertNotNull($row->tested_at);
        $this->assertStringNotContainsString('failed', (string) $row->test_note, 'the answer may repeat a key, so only the status is kept');
    }

    public function test_the_try_button_really_sends_even_in_test_mode_and_remembers_the_answer(): void
    {
        Http::fake(['https://8.8.8.8/*' => Http::response('OK', 200)]);
        $this->save('sms:http', $this->sms(), mode: 'test');

        $this->actingAs($this->admin)->post('/admin/connections/sms:http/test', ['to' => '01712345678'])->assertSessionHasNoErrors();

        Http::assertSentCount(1);
        $this->assertTrue(Integration::where('key', 'sms:http')->firstOrFail()->test_ok);
        $this->assertTrue($this->provider('sms:http')['test_ok']);
    }

    public function test_the_try_button_wants_a_real_number_and_saved_keys(): void
    {
        Http::fake();
        $this->actingAs($this->admin)->post('/admin/connections/sms:http/test', ['to' => '01712345678'])->assertSessionHasErrors('connection');

        $this->save('sms:http', $this->sms());
        $this->actingAs($this->admin)->post('/admin/connections/sms:http/test', ['to' => 'not a number'])->assertSessionHasNoErrors();

        Http::assertNothingSent();
        $this->assertFalse(Integration::where('key', 'sms:http')->firstOrFail()->test_ok);
    }

    public function test_an_address_that_points_inside_is_never_called(): void
    {
        Http::fake();
        foreach (['https://127.0.0.1/send', 'https://10.0.0.5/send', 'https://192.168.1.1/send', 'https://localhost/send', 'https://169.254.169.254/latest'] as $url) {
            $this->save('sms:http', $this->sms(['url' => $url]));
            $this->actingAs($this->admin)->post('/admin/connections/sms:http/test', ['to' => '01712345678']);
        }

        Http::assertNothingSent();
        $this->assertFalse(Integration::where('key', 'sms:http')->firstOrFail()->test_ok);
    }

    public function test_the_service_object_returns_values_only_for_a_service_that_is_on(): void
    {
        $svc = app(Integrations::class);
        $this->save('sms:http', $this->sms());
        $this->assertNull($svc->active('sms:http'));

        $this->save('sms:http', $this->sms(), enabled: true, mode: 'live');
        $on = app(Integrations::class)->active('sms:http');
        $this->assertSame('live', $on['mode']);
        $this->assertSame('https://8.8.8.8/send', $on['settings']['url']);

        $this->expectException(StockException::class);
        $svc->provider('nothing:here');
    }
}
