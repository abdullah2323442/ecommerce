<?php

namespace Tests\Feature\Marketing;

use App\Enums\AccountType;
use App\Http\Middleware\SetLocale;
use App\Jobs\SendCampaignSms;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\SmsCampaign;
use App\Models\SmsRecipient;
use App\Models\Tier;
use App\Models\User;
use App\Services\Marketing\BulkSms;
use App\Services\Messages\ConfiguredSmsGateway;
use App\Services\Stock\StockException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 27, bulk SMS: one message to a chosen group, a test first, a daily limit, and a way for everyone to stop. */
class BulkSmsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Tier $a;

    private Tier $b;

    /** @var array<int, array{0: string, 1: string}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCookie(SetLocale::COOKIE, 'en');
        $this->admin = User::factory()->create(['account_type' => AccountType::Admin, 'phone' => '01700000001']);
        $this->a = Tier::create(['name' => 'A', 'discount_percent' => 15]);
        $this->b = Tier::create(['name' => 'B', 'discount_percent' => 10]);
        $this->sent = [];
        $this->mock(ConfiguredSmsGateway::class, fn ($m) => $m->shouldReceive('send')->andReturnUsing(function (string $phone, string $text) {
            $this->sent[] = [$phone, $text];
        }));
    }

    private function shop(string $phone, array $more = []): User
    {
        return User::factory()->create($more + ['account_type' => AccountType::Paikari, 'phone' => $phone, 'tier_id' => $this->a->id, 'area' => 'Mirpur', 'created_at' => now()->subYear()]);
    }

    private function bulk(): BulkSms
    {
        return app(BulkSms::class);
    }

    private function people(array $filters = []): array
    {
        return $this->bulk()->people($this->bulk()->filters($filters))->pluck('phone')->all();
    }

    // ------------------------------------------------------------------ who

    public function test_everyone_with_a_real_mobile_number_is_in_by_default_and_staff_are_not(): void
    {
        $this->shop('01711111111');
        $this->shop('01811111111', ['account_type' => AccountType::Wholesaler]);
        $this->shop('01911111111', ['account_type' => AccountType::Public]);
        $this->shop('01611111111', ['account_type' => AccountType::Staff]);

        $this->assertEqualsCanonicalizing(['01711111111', '01811111111', '01911111111'], $this->people());
    }

    public function test_bad_numbers_suspended_accounts_and_those_who_stopped_are_left_out(): void
    {
        $this->shop('01711111111');
        $this->shop('12345');
        $this->shop('0171111111a');
        $this->shop('01211111111'); // not a mobile range
        $this->shop('01811111111', ['suspended_at' => now()]);
        $this->shop('01911111111', ['marketing_optout_at' => now()]);
        $this->shop('01711111111x');

        $this->assertSame(['01711111111'], $this->people());
    }

    public function test_it_can_be_narrowed_by_type_tier_and_area(): void
    {
        $this->shop('01711111111');
        $this->shop('01722222222', ['tier_id' => $this->b->id, 'area' => 'Pallabi']);
        $this->shop('01833333333', ['account_type' => AccountType::Wholesaler]);

        $this->assertSame(['01711111111', '01722222222'], $this->people(['types' => ['paikari']]));
        $this->assertSame(['01722222222'], $this->people(['types' => ['paikari'], 'tier_ids' => [$this->b->id]]));
        $this->assertSame(['01722222222'], $this->people(['area' => 'palla']));
        $this->assertSame([], $this->people(['area' => 'Gulshan']));
    }

    public function test_those_who_have_not_ordered_for_a_while_are_found(): void
    {
        $busy = $this->shop('01711111111');
        $idle = $this->shop('01722222222');
        $new = $this->shop('01833333333', ['created_at' => now()->subDays(3)]);
        Invoice::create(['kind' => 'paikari', 'channel' => 'online', 'status' => 'final', 'customer_id' => $busy->id, 'number' => 'M2026-000001', 'total' => 100, 'finalised_at' => now()->subDays(5), 'completed_at' => now()->subDays(5), 'opened_at' => now()->subDays(5)]);

        $this->assertSame(['01722222222'], $this->people(['inactive_days' => 30]));
        $this->assertNotContains($new->phone, $this->people(['inactive_days' => 30]));
    }

    public function test_those_who_owe_money_are_found(): void
    {
        $owes = $this->shop('01711111111');
        $this->shop('01722222222');
        Invoice::create(['kind' => 'paikari', 'channel' => 'counter', 'status' => 'final', 'customer_id' => $owes->id, 'number' => 'M2026-000002', 'total' => 500, 'paid_amount' => 100, 'finalised_at' => now()->subDays(2), 'opened_at' => now()->subDays(2)]);

        $this->assertSame(['01711111111'], $this->people(['owes' => true]));
    }

    public function test_nonsense_in_the_filters_is_dropped(): void
    {
        $f = $this->bulk()->filters(['types' => ['paikari', 'king'], 'tier_ids' => [999999, $this->a->id], 'inactive_days' => 3, 'area' => str_repeat('x', 200)]);

        $this->assertSame(['paikari'], $f['types']);
        $this->assertSame([$this->a->id], $f['tier_ids']);
        $this->assertNull($f['inactive_days']);
        $this->assertSame(80, mb_strlen($f['area']));
    }

    // ------------------------------------------------------------------ the words

    public function test_segments_follow_the_alphabet_bangla_takes_seventy_letters_english_a_hundred_and_sixty(): void
    {
        $this->assertSame(1, $this->bulk()->segments(str_repeat('a', 160)));
        $this->assertSame(2, $this->bulk()->segments(str_repeat('a', 161)));
        $this->assertSame(1, $this->bulk()->segments(str_repeat('অ', 70)));
        $this->assertSame(2, $this->bulk()->segments(str_repeat('অ', 71)));
        $this->assertSame(3, $this->bulk()->segments(str_repeat('অ', 135)));
    }

    public function test_a_campaign_needs_a_name_words_of_sensible_length_and_somebody_to_send_to(): void
    {
        $this->shop('01711111111');
        $make = fn (array $over = []) => $this->bulk()->create($over + ['name' => 'Eid offer', 'body' => 'Eid offer: 10 percent off all week.'], $this->admin);

        foreach ([['name' => ''], ['body' => 'Hi'], ['body' => str_repeat('x', 451)], ['area' => 'Nowhere at all']] as $bad) {
            try {
                $make($bad);
                $this->fail('A bad campaign was saved.');
            } catch (StockException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame(0, SmsCampaign::count());
        $c = $make();
        $this->assertSame(1, $c->recipients);
        $this->assertSame('draft', $c->status);
        $this->assertSame('Everyone with a mobile number', $c->audience_label);
    }

    // ------------------------------------------------------------------ sending

    public function test_sending_writes_everyone_down_then_sends_each_text_with_their_own_stop_link(): void
    {
        $one = $this->shop('01711111111');
        $two = $this->shop('01722222222');
        $c = $this->bulk()->create(['name' => 'Eid', 'body' => 'Eid offer: 10 percent off all week.'], $this->admin);

        $this->bulk()->start($c, $this->admin);

        $this->assertCount(2, $this->sent);
        $by = collect($this->sent)->mapWithKeys(fn ($s) => [$s[0] => $s[1]]);
        $this->assertStringStartsWith('Eid offer: 10 percent off all week.', $by['01711111111']);
        $this->assertStringContainsString('/u/'.$one->id.'/'.$this->bulk()->token($one->id), $by['01711111111']);
        $this->assertStringContainsString('/u/'.$two->id.'/'.$this->bulk()->token($two->id), $by['01722222222']);
        $this->assertSame(['sent', 'sent'], SmsRecipient::orderBy('id')->pluck('status')->all());
        $this->assertSame('done', $c->fresh()->status);
        $this->assertNotNull($c->fresh()->finished_at);
    }

    public function test_the_texts_are_queued_a_few_each_second_not_all_at_once(): void
    {
        Queue::fake();
        Setting::write('sms.bulk_per_second', 2);
        foreach (range(1, 5) as $i) {
            $this->shop('0171111111'.$i);
        }
        $c = $this->bulk()->create(['name' => 'Eid', 'body' => 'Eid offer: 10 percent off all week.'], $this->admin);

        $this->bulk()->start($c, $this->admin);

        Queue::assertPushed(SendCampaignSms::class, 5);
        $delays = collect(Queue::pushedJobs()[SendCampaignSms::class])->map(fn ($j) => (int) round(now()->diffInSeconds($j['job']->delay, false)))->sort()->values()->all();
        $this->assertSame([0, 0, 1, 1, 2], $delays);
        $this->assertSame('sending', $c->fresh()->status);
    }

    public function test_a_campaign_is_sent_once(): void
    {
        $this->shop('01711111111');
        $c = $this->bulk()->create(['name' => 'Eid', 'body' => 'Eid offer: 10 percent off all week.'], $this->admin);

        $this->bulk()->start($c, $this->admin);

        $this->expectException(StockException::class);
        $this->bulk()->start($c->fresh(), $this->admin);
    }

    public function test_the_daily_limit_holds_across_campaigns(): void
    {
        Setting::write('sms.bulk_daily_limit', 3);
        foreach (range(1, 4) as $i) {
            $this->shop('0171111111'.$i);
        }
        $c = $this->bulk()->create(['name' => 'Eid', 'body' => 'Eid offer: 10 percent off all week.'], $this->admin);

        try {
            $this->bulk()->start($c, $this->admin);
            $this->fail('Sent over the limit.');
        } catch (StockException $e) {
            $this->assertStringContainsString('only 3 are left', $e->getMessage());
        }

        $this->assertSame(0, SmsRecipient::count());
        $this->assertSame([], $this->sent);
    }

    public function test_someone_who_stops_before_their_text_goes_out_is_skipped(): void
    {
        Queue::fake();
        $one = $this->shop('01711111111');
        $two = $this->shop('01722222222');
        $c = $this->bulk()->create(['name' => 'Eid', 'body' => 'Eid offer: 10 percent off all week.'], $this->admin);
        $this->bulk()->start($c, $this->admin);

        $one->forceFill(['marketing_optout_at' => now()])->save();
        foreach (SmsRecipient::orderBy('id')->get() as $r) {
            $this->bulk()->deliver($r->id);
        }

        $this->assertSame([['01722222222']], collect($this->sent)->map(fn ($s) => [$s[0]])->all());
        $this->assertSame('failed', SmsRecipient::where('user_id', $one->id)->value('status'));
        $this->assertSame('sent', SmsRecipient::where('user_id', $two->id)->value('status'));
        $this->assertSame('done', $c->fresh()->status);
    }

    public function test_a_failing_text_is_kept_on_the_person_and_the_rest_still_go(): void
    {
        $this->shop('01711111111');
        $this->shop('01722222222');
        $this->mock(ConfiguredSmsGateway::class, fn ($m) => $m->shouldReceive('send')->andReturnUsing(function (string $phone, string $text) {
            if ($phone === '01711111111') {
                throw new \RuntimeException('Gateway down');
            }
            $this->sent[] = [$phone, $text];
        }));
        $c = $this->bulk()->create(['name' => 'Eid', 'body' => 'Eid offer: 10 percent off all week.'], $this->admin);

        $this->bulk()->start($c, $this->admin);

        $this->assertSame(['failed', 'sent'], SmsRecipient::orderBy('id')->pluck('status')->all());
        $this->assertSame('RuntimeException', SmsRecipient::orderBy('id')->value('note'));
        $this->assertSame('done', $c->fresh()->status);
    }

    public function test_stopping_a_campaign_that_is_going_out_keeps_the_rest_from_being_sent(): void
    {
        Queue::fake();
        $this->shop('01711111111');
        $this->shop('01722222222');
        $c = $this->bulk()->create(['name' => 'Eid', 'body' => 'Eid offer: 10 percent off all week.'], $this->admin);
        $this->bulk()->start($c, $this->admin);

        $this->bulk()->cancel($c->fresh(), $this->admin);
        foreach (SmsRecipient::all() as $r) {
            $this->bulk()->deliver($r->id);
        }

        $this->assertSame([], $this->sent);
        $this->assertSame('cancelled', $c->fresh()->status);
    }

    public function test_a_test_goes_to_the_owners_own_number_with_the_same_ending(): void
    {
        $this->shop('01711111111');
        $c = $this->bulk()->create(['name' => 'Eid', 'body' => 'Eid offer: 10 percent off all week.'], $this->admin);

        $this->actingAs($this->admin)->post("/admin/sms/{$c->id}/test", ['phone' => '01712345678'])->assertSessionHasNoErrors();

        $this->assertCount(1, $this->sent);
        $this->assertSame('01712345678', $this->sent[0][0]);
        $this->assertStringContainsString('To stop these messages:', $this->sent[0][1]);
        $this->assertSame(0, SmsRecipient::count());

        $this->post("/admin/sms/{$c->id}/test", ['phone' => '12345'])->assertSessionHasErrors('test');
    }

    // ------------------------------------------------------------------ stopping

    public function test_the_stop_link_shows_a_button_first_and_only_the_button_stops_the_texts(): void
    {
        $u = $this->shop('01711111111');
        $token = $this->bulk()->token($u->id);

        $this->get("/u/{$u->id}/{$token}")->assertOk()->assertInertia(fn (Assert $p) => $p->component('stop-texts')->where('stopped', false));
        $this->assertNull($u->fresh()->marketing_optout_at);

        $this->post("/u/{$u->id}/{$token}")->assertOk()->assertInertia(fn (Assert $p) => $p->where('stopped', true));
        $this->assertNotNull($u->fresh()->marketing_optout_at);
        $this->assertNotContains('01711111111', $this->people());
        $this->assertDatabaseHas('activity_logs', ['action' => 'sms.optout', 'subject_id' => $u->id]);
    }

    public function test_a_guessed_or_wrong_link_does_nothing(): void
    {
        $u = $this->shop('01711111111');
        $other = $this->shop('01722222222');

        // A page that does not exist for you answers like a missing one: a quiet redirect home, and nothing changes.
        $this->get("/u/{$u->id}/".$this->bulk()->token($other->id))->assertRedirect('/');
        $this->post("/u/{$u->id}/".$this->bulk()->token($other->id))->assertRedirect('/');
        $this->post("/u/{$u->id}/000000000000")->assertRedirect('/');
        $this->post('/u/99999/'.$this->bulk()->token(99999))->assertRedirect('/');
        $this->assertNull($u->fresh()->marketing_optout_at);
    }

    public function test_the_owner_can_put_someone_back_who_asked(): void
    {
        $u = $this->shop('01711111111', ['marketing_optout_at' => now()]);

        $this->bulk()->resume($u, $this->admin);

        $this->assertContains('01711111111', $this->people());
    }

    // ------------------------------------------------------------------ the screens

    public function test_the_page_lists_campaigns_the_service_state_and_the_limit(): void
    {
        $this->shop('01711111111');
        $c = $this->bulk()->create(['name' => 'Eid', 'body' => 'Eid offer: 10 percent off all week.'], $this->admin);

        $this->actingAs($this->admin)->get('/admin/sms')->assertInertia(fn (Assert $p) => $p
            ->component('admin/sms/index')->has('campaigns', 1)->where('campaigns.0.name', 'Eid')->where('service.on', false)->where('limit.daily', 2000)->has('tiers', 2)->where('maxBody', 450));
        $this->get("/admin/sms/{$c->id}")->assertInertia(fn (Assert $p) => $p->component('admin/sms/show')->where('campaign.recipients', 1)->where('segments', 1));
    }

    public function test_the_live_count_answers_while_the_owner_is_choosing(): void
    {
        $this->shop('01711111111');
        $this->shop('01722222222', ['area' => 'Pallabi']);

        $this->actingAs($this->admin)->getJson('/admin/sms/audience?area=Pallabi&body='.urlencode('Hello there'))->assertOk()->assertJson(['count' => 1])->assertJsonStructure(['count', 'label', 'segments', 'letters']);
    }

    public function test_the_recipient_list_hides_most_of_each_number(): void
    {
        $this->shop('01711111111');
        $c = $this->bulk()->create(['name' => 'Eid', 'body' => 'Eid offer: 10 percent off all week.'], $this->admin);
        $this->bulk()->start($c, $this->admin);

        $this->actingAs($this->admin)->get("/admin/sms/{$c->id}")->assertInertia(fn (Assert $p) => $p->where('people.0.phone', '*******1111'));
    }

    public function test_only_those_who_manage_customers_can_use_it(): void
    {
        $staff = User::factory()->create(['account_type' => AccountType::Staff]);
        $shop = $this->shop('01711111111');
        $c = SmsCampaign::create(['name' => 'x', 'body' => 'Hello there friends', 'audience' => [], 'audience_label' => 'x', 'status' => 'draft', 'recipients' => 1]);

        $this->get('/admin/sms')->assertRedirect('/login');
        foreach ([$staff, $shop] as $who) {
            $this->actingAs($who)->get('/admin/sms')->assertRedirect('/');
            $this->actingAs($who)->post('/admin/sms', ['name' => 'a', 'body' => 'Hello there friends'])->assertRedirect('/');
            $this->actingAs($who)->post("/admin/sms/{$c->id}/start")->assertRedirect('/');
        }
        $this->assertSame([], $this->sent);
        $this->assertSame('draft', $c->fresh()->status);
    }
}
