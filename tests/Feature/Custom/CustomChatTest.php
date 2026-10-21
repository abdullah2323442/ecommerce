<?php

namespace Tests\Feature\Custom;

use App\Enums\AccountType;
use App\Models\CustomChatBlock;
use App\Models\CustomChatMessage;
use App\Models\CustomMaterial;
use App\Models\CustomOrder;
use App\Models\Notice;
use App\Models\Sector;
use App\Models\Setting;
use App\Models\User;
use App\Services\Custom\Craftsmen;
use App\Services\Custom\CustomChat;
use App\Services\Custom\CustomOrders;
use App\Services\Stock\StockException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Spec ch 23 and 16.5: chat in a made-to-order job — under our name, no numbers or links, two cards, we read everything. */
class CustomChatTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;

    private User $staff;

    private User $maker;

    private CustomChat $chat;

    private CustomOrders $orders;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        Carbon::setTestNow('2026-10-15 09:00:00');
        $this->buyer = User::factory()->create(['account_type' => AccountType::Public, 'name' => 'Buyer Bibi', 'phone' => '01712345678']);
        $this->staff = User::factory()->create(['account_type' => AccountType::Staff, 'name' => 'Office Staff']);
        $this->maker = User::factory()->create(['account_type' => AccountType::Craftsman, 'name' => 'Karim Mistri']);
        $this->chat = app(CustomChat::class);
        $this->orders = app(CustomOrders::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function order(): CustomOrder
    {
        $teak = CustomMaterial::firstOrCreate(['name' => 'Teak'], ['kind' => 'wood', 'rate' => 3000]);

        return $this->orders->place($this->buyer, ['kind' => 'wood', 'material_id' => $teak->id, 'items' => [['values' => ['length' => 72], 'unit' => 'inch']]]);
    }

    /** A job in the hands of Karim. */
    private function making(): CustomOrder
    {
        $o = $this->order();
        $o = $this->orders->price($o, $this->staff, ['material_cost' => 4000, 'wages_total' => 1500, 'base_price' => 9000, 'days_needed' => 10]);
        $o = $this->orders->confirm($o, $this->buyer, true);
        $this->orders->receiveAdvance($o, $this->staff, 2700);
        $o = app(Craftsmen::class)->assign($o->fresh(), $this->staff, $this->maker);

        return $this->orders->start($o, $this->staff);
    }

    // ------------------------------------------------------------------ numbers and links

    public function test_phone_numbers_in_any_dress_are_caught(): void
    {
        foreach (['call 01712345678', 'my number is 017 1234 5678', '০১৭১২৩৪৫৬৭৮', '+880-171-234-5678', 'zero one seven 01712-345-678 ok'] as $text) {
            $this->assertSame('number', $this->chat->problem($text), $text);
        }
    }

    public function test_links_and_handles_are_caught(): void
    {
        foreach (['https://example.com', 'www.shop.com', 'find me on facebook.com/siam', 'wa.me/8801712', 'mail me@gmail.com', 'my id @karim_wood'] as $text) {
            $this->assertSame('link', $this->chat->problem($text), $text);
        }
    }

    public function test_ordinary_talk_and_measurements_pass(): void
    {
        foreach (['Can it be 72 inches wide and 36 high?', 'Make 3 drawers, polish in 2 coats', 'I need it by the 25th, price 12000 ok?', 'Is teak 1.5 inch thick enough'] as $text) {
            $this->assertNull($this->chat->problem($text), $text);
        }
    }

    public function test_a_blocked_message_is_not_sent_but_is_kept_for_us_to_see(): void
    {
        $o = $this->order();

        try {
            $this->chat->send($o, $this->buyer, 'call me on 01712345678');
            $this->fail('should be blocked');
        } catch (StockException) {
        }

        $this->assertSame(0, CustomChatMessage::count());
        $block = CustomChatBlock::firstOrFail();
        $this->assertSame(['number', 'buyer', $this->buyer->id], [$block->reason, $block->role, $block->user_id]);
    }

    // ------------------------------------------------------------------ talking

    public function test_text_photo_and_voice_can_all_be_sent(): void
    {
        $o = $this->order();

        $made = $this->chat->send($o, $this->buyer, 'Like this please', UploadedFile::fake()->image('room.jpg'), UploadedFile::fake()->create('v.webm', 20, 'audio/webm'));

        $this->assertSame(['text', 'photo', 'voice'], $made->pluck('kind')->all());
        $this->assertNotNull($made[1]->file_path);
    }

    public function test_an_empty_message_is_refused(): void
    {
        $this->expectException(StockException::class);
        $this->chat->send($this->order(), $this->buyer, '   ');
    }

    public function test_the_buyer_sees_our_name_and_never_the_maker(): void
    {
        $o = $this->making();
        $this->chat->send($o, $this->maker, 'The wood has come, starting tomorrow');
        $this->chat->send($o, $this->buyer, 'Thank you');

        $seen = $this->chat->messagesFor($o, $this->buyer);

        $this->assertSame(config('brand.name'), $seen[0]['who']);
        $this->assertTrue($seen[1]['mine']);
        $this->assertStringNotContainsString('Karim', json_encode($seen));
    }

    public function test_the_maker_sees_the_buyer_without_name_or_number(): void
    {
        $o = $this->making();
        $this->chat->send($o, $this->buyer, 'Hello there');
        $this->chat->send($o, $this->staff, 'We are on it');

        $seen = $this->chat->messagesFor($o, $this->maker);
        $json = json_encode($seen);

        $this->assertSame([__('The buyer'), __('The office')], [$seen[0]['who'], $seen[1]['who']]);
        foreach (['Buyer Bibi', '01712345678', 'Office Staff'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    public function test_the_maker_never_sees_the_offer_card_with_the_price_the_buyer_pays(): void
    {
        $o = $this->order();
        $offer = $this->chat->sendOffer($o, $this->staff, 9000, 10, 40, 'Includes polish');
        $this->chat->respondOffer($offer->setRelation('order', $o->fresh()), $this->buyer, true, true);
        $o = app(Craftsmen::class)->setWages($o->fresh(), $this->staff, 1500);
        $this->orders->receiveAdvance($o->fresh(), $this->staff, 3600);
        $o = app(Craftsmen::class)->assign($o->fresh(), $this->staff, $this->maker);
        $this->orders->start($o, $this->staff);
        $this->chat->send($o, $this->staff, 'Please polish it twice');

        $maker = json_encode($this->chat->messagesFor($o, $this->maker));
        $buyer = json_encode($this->chat->messagesFor($o, $this->buyer));

        $this->assertStringNotContainsString('9000', $maker, 'the buyer price is not the maker business');
        $this->assertStringNotContainsString('"kind":"offer"', $maker);
        $this->assertStringContainsString('Please polish it twice', $maker, 'ordinary messages still reach the maker');
        $this->assertStringContainsString('"kind":"offer"', $buyer, 'the buyer still gets the offer');
    }

    public function test_we_see_every_chat_with_real_names(): void
    {
        $o = $this->making();
        $this->chat->send($o, $this->maker, 'Done the frame');
        $this->chat->send($o, $this->buyer, 'Great');

        $seen = $this->chat->messagesFor($o, $this->staff);

        $this->assertSame(['Karim Mistri (maker)', __('The buyer').' (buyer)'], [$seen[0]['who'], $seen[1]['who']]);
    }

    public function test_only_the_three_sides_have_a_place_in_the_chat(): void
    {
        $o = $this->making();
        $otherMaker = User::factory()->create(['account_type' => AccountType::Craftsman]);
        $stranger = User::factory()->create(['account_type' => AccountType::Public]);

        foreach ([$otherMaker, $stranger] as $user) {
            $this->assertNull($this->chat->roleOf($o, $user));
            try {
                $this->chat->send($o, $user, 'Hi');
                $this->fail('no place in this chat');
            } catch (StockException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_a_maker_who_was_given_another_job_is_out_of_the_chat(): void
    {
        $o = $this->making();
        $new = User::factory()->create(['account_type' => AccountType::Craftsman]);
        app(Craftsmen::class)->shift($o->fresh(), $this->maker, $new);

        $this->assertNull($this->chat->roleOf($o->fresh(), $this->maker));
        $this->assertSame('maker', $this->chat->roleOf($o->fresh(), $new));
    }

    public function test_a_cancelled_job_is_closed_and_the_toggle_can_switch_chat_off(): void
    {
        $o = $this->order();
        Setting::write('custom.chat_wood', false);
        try {
            $this->chat->send($o, $this->buyer, 'Hello');
            $this->fail('chat is off');
        } catch (StockException) {
        }

        Setting::write('custom.chat_wood', true);
        $this->orders->cancel($o, $this->buyer, 'buyer');
        $this->expectException(StockException::class);
        $this->chat->send($o->fresh(), $this->buyer, 'Hello');
    }

    public function test_once_a_wood_sector_is_opened_its_own_chat_choice_decides(): void
    {
        $o = $this->order();
        $wood = Sector::create(['slug' => 'wood', 'name' => 'Wood', 'unit' => 'foot', 'chat' => false, 'custom_orders' => true]);

        $this->assertFalse($this->chat->enabled($o), 'the sector says no chat');
        $this->expectException(StockException::class);
        try {
            $this->chat->send($o, $this->buyer, 'Hello');
        } finally {
            $wood->update(['chat' => true]);
            $this->assertTrue($this->chat->enabled($o->fresh()), 'the sector says yes, whatever the old setting says');
            Setting::write('custom.chat_wood', false);
            $this->assertTrue($this->chat->enabled($o->fresh()));
        }
    }

    public function test_with_no_sector_opened_the_older_setting_still_decides(): void
    {
        $o = $this->order();

        $this->assertTrue($this->chat->enabled($o));
        Setting::write('custom.chat_wood', false);
        $this->assertFalse($this->chat->enabled($o));
    }

    public function test_a_sector_that_takes_no_made_to_order_jobs_refuses_them(): void
    {
        Sector::create(['slug' => 'wood', 'name' => 'Wood', 'unit' => 'foot', 'custom_orders' => false]);
        $teak = CustomMaterial::firstOrCreate(['name' => 'Teak'], ['kind' => 'wood', 'rate' => 3000]);

        $this->expectException(StockException::class);
        $this->orders->place($this->buyer, ['kind' => 'wood', 'material_id' => $teak->id, 'items' => [['values' => ['length' => 72], 'unit' => 'inch']]]);
    }

    public function test_clothing_jobs_are_not_stopped_by_the_wood_sector(): void
    {
        Sector::create(['slug' => 'wood', 'name' => 'Wood', 'unit' => 'foot', 'custom_orders' => false]);

        $this->assertNotNull(Sector::where('slug', 'wood')->first());
        $this->assertNull(Sector::where('slug', 'clothing')->first(), 'no clothing sector, so clothing keeps working as before');
    }

    // ------------------------------------------------------------------ the two cards

    public function test_sending_measurements_replaces_them_and_brings_a_price_back_to_us(): void
    {
        $o = $this->orders->price($this->order(), $this->staff, ['material_cost' => 4000, 'wages_total' => 1500, 'base_price' => 9000, 'days_needed' => 10]);
        $this->assertSame('priced', $o->status);

        $card = $this->chat->sendMeasurements($o, $this->buyer, [['values' => ['length' => 80, 'width' => 30], 'unit' => 'inch']]);

        $this->assertSame('measurements', $card->kind);
        $this->assertEquals(80, $o->fresh()->items[0]['values']['length']);
        $this->assertSame('requested', $o->fresh()->status);   // the old price was for other measurements
        $this->assertSame(80, $this->chat->latestMeasurements($o->fresh())['items'][0]['values']['length'] + 0);
    }

    public function test_agreed_measurements_cannot_be_swapped_from_the_chat(): void
    {
        $o = $this->making();

        $this->expectException(StockException::class);
        $this->chat->sendMeasurements($o, $this->buyer, [['values' => ['length' => 99], 'unit' => 'inch']]);
    }

    public function test_only_the_buyer_sends_measurements_and_only_we_send_offers(): void
    {
        $o = $this->order();

        foreach ([fn () => $this->chat->sendMeasurements($o, $this->staff, [['values' => ['length' => 1], 'unit' => 'inch']]), fn () => $this->chat->sendOffer($o, $this->buyer, 100, 5)] as $try) {
            try {
                $try();
                $this->fail('wrong side');
            } catch (StockException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_an_offer_sets_the_price_and_accepting_it_confirms_the_job(): void
    {
        $o = $this->order();

        $offer = $this->chat->sendOffer($o, $this->staff, 9000, 10, 40, 'Includes polish');
        $this->assertSame(['pending', 9000.0, 10, 40], [$offer->meta['state'], (float) $offer->meta['total'], $offer->meta['days'], $offer->meta['advance_percent']]);
        $this->assertSame('priced', $o->fresh()->status);

        $done = $this->chat->respondOffer($offer->setRelation('order', $o->fresh()), $this->buyer, true, true);

        $this->assertSame('accepted', $done->meta['state']);
        $this->assertSame('confirmed', $o->fresh()->status);
    }

    public function test_accepting_needs_the_measurements_to_be_checked(): void
    {
        $o = $this->order();
        $offer = $this->chat->sendOffer($o, $this->staff, 9000, 10);

        $this->expectException(StockException::class);
        $this->chat->respondOffer($offer->setRelation('order', $o->fresh()), $this->buyer, true, false);
    }

    public function test_a_newer_offer_makes_the_older_one_void(): void
    {
        $o = $this->order();
        $first = $this->chat->sendOffer($o, $this->staff, 9000, 10);
        $this->chat->sendOffer($o->fresh(), $this->staff, 8000, 8);

        $this->assertSame('superseded', $first->fresh()->meta['state']);
        $this->expectException(StockException::class);
        $this->chat->respondOffer($first->fresh()->setRelation('order', $o->fresh()), $this->buyer, true, true);
    }

    public function test_declining_leaves_the_job_with_its_price(): void
    {
        $o = $this->order();
        $offer = $this->chat->sendOffer($o, $this->staff, 9000, 10);

        $this->assertSame('declined', $this->chat->respondOffer($offer->setRelation('order', $o->fresh()), $this->buyer, false)->meta['state']);
        $this->assertSame('priced', $o->fresh()->status);
    }

    public function test_an_offer_keeps_the_costs_already_set(): void
    {
        $o = $this->orders->price($this->order(), $this->staff, ['material_cost' => 4000, 'wages_total' => 1500, 'base_price' => 9000, 'days_needed' => 10]);
        $this->chat->sendOffer($o, $this->staff, 8500, 9);

        $this->assertEquals([4000, 1500, 8500], [$o->fresh()->material_cost, $o->fresh()->wages_total, $o->fresh()->price]);

        $bare = $this->chat->sendOffer($this->order(), $this->staff, 5000, 5);
        $this->assertNull($bare->order->fresh()->wages_total);   // wages are not agreed by an offer
    }

    // ------------------------------------------------------------------ notice, reading, watching

    public function test_the_other_side_is_told_once_until_they_look(): void
    {
        $o = $this->making();

        $this->chat->send($o, $this->buyer, 'One');
        $this->chat->send($o, $this->buyer, 'Two');
        $this->chat->send($o, $this->buyer, 'Three');
        $this->assertSame(1, Notice::where('user_id', $this->maker->id)->where('kind', 'craftsman_chat')->count());
        $this->assertSame(3, $this->chat->unread($o->fresh(), 'maker'));

        $this->chat->markRead($o->fresh(), 'maker');
        $this->chat->send($o->fresh(), $this->buyer, 'Four');
        $this->assertSame(2, Notice::where('user_id', $this->maker->id)->where('kind', 'craftsman_chat')->count());

        $this->chat->send($o->fresh(), $this->maker, 'Reply');
        $this->assertSame(1, Notice::where('user_id', $this->buyer->id)->where('kind', 'custom_chat')->count());
    }

    public function test_a_buyer_who_is_not_answered_brings_it_to_us_once(): void
    {
        $o = $this->making();
        $this->chat->send($o, $this->buyer, 'Is anyone there?');

        Carbon::setTestNow('2026-10-15 15:00:00');   // six hours; the wait is twelve
        $this->assertSame(0, $this->chat->watch());

        Carbon::setTestNow('2026-10-15 22:00:00');
        $this->assertSame(1, $this->chat->watch());
        $this->assertSame(0, $this->chat->watch());
        $this->assertSame(1, Notice::where('user_id', $this->staff->id)->where('kind', 'custom_unanswered')->count());
        $this->assertNotNull(CustomChatMessage::first()->unanswered_at);
    }

    public function test_an_answered_buyer_is_left_alone(): void
    {
        $o = $this->making();
        $this->chat->send($o, $this->buyer, 'Is anyone there?');
        $this->chat->send($o, $this->maker, 'Yes');

        Carbon::setTestNow('2026-10-16 22:00:00');
        $this->assertSame(0, $this->chat->watch());
    }

    // ------------------------------------------------------------------ the pages

    public function test_the_buyer_chats_from_the_job_page(): void
    {
        $o = $this->order();

        $this->actingAs($this->buyer)->post("/custom/{$o->public_token}/chat", ['text' => 'Can the top be glass?'])->assertSessionHasNoErrors();
        $this->post("/custom/{$o->public_token}/chat", ['text' => 'ring 01712345678'])->assertSessionHasErrors('order');

        $this->get("/custom/{$o->public_token}")->assertInertia(fn (Assert $p) => $p->component('custom/show')->where('chat.enabled', true)->has('chat.messages', 1)->where('chat.can_measure', true)->has('measure.fields'));
        $this->assertSame(0, $this->chat->unread($o->fresh(), 'buyer'));
    }

    public function test_a_poll_does_not_count_as_reading(): void
    {
        $o = $this->making();
        $this->chat->send($o, $this->maker, 'Hello');

        $version = $this->actingAs($this->buyer)->get('/custom')->viewData('page')['version'];
        $this->get("/custom/{$o->public_token}", ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Inertia-Partial-Component' => 'custom/show', 'X-Inertia-Partial-Data' => 'chat'])->assertOk();
        $this->assertSame(1, $this->chat->unread($o->fresh(), 'buyer'));

        $this->get("/custom/{$o->public_token}")->assertOk();
        $this->assertSame(0, $this->chat->unread($o->fresh(), 'buyer'));
    }

    public function test_the_buyer_sends_measurements_and_answers_an_offer_from_the_page(): void
    {
        $o = $this->order();

        $this->actingAs($this->buyer)->post("/custom/{$o->public_token}/chat/measurements", ['items' => [['values' => ['length' => 90], 'unit' => 'inch']]])->assertSessionHasNoErrors();
        $offer = $this->chat->sendOffer($o->fresh(), $this->staff, 9000, 10);

        $this->actingAs($this->buyer)->post("/custom/{$o->public_token}/chat/{$offer->id}/offer", ['answer' => 'accept', 'checked' => true])->assertSessionHasNoErrors();
        $this->assertSame('confirmed', $o->fresh()->status);
    }

    public function test_another_buyer_cannot_use_the_chat_or_its_files(): void
    {
        $o = $this->order();
        $made = $this->chat->send($o, $this->buyer, null, UploadedFile::fake()->image('a.jpg'))->first();
        $other = User::factory()->create(['account_type' => AccountType::Paikari]);

        $this->actingAs($other)->post("/custom/{$o->public_token}/chat", ['text' => 'Hi'])->assertRedirect('/');
        $this->actingAs($other)->get("/custom/{$o->public_token}/chat/{$made->id}/file")->assertRedirect('/');
        $this->actingAs($this->buyer)->get("/custom/{$o->public_token}/chat/{$made->id}/file")->assertOk();
    }

    public function test_the_maker_chats_from_the_job_page_and_never_sees_the_buyer(): void
    {
        $o = $this->making();
        $this->chat->send($o, $this->buyer, 'Hello maker');
        $json = '';

        $this->actingAs($this->maker)->post("/craftsman/jobs/{$o->id}/chat", ['text' => 'Hello, it is going well'])->assertSessionHasNoErrors();
        $this->get("/craftsman/jobs/{$o->id}")->assertInertia(function (Assert $p) use (&$json) {
            $p->component('craftsman/show')->where('chat.role', 'maker')->has('chat.messages', 2);
            $json = json_encode($p->toArray()['props']['chat']);
        });

        $this->assertStringNotContainsString('Buyer Bibi', $json);
        $this->assertStringNotContainsString('01712345678', $json);
    }

    public function test_a_helper_sees_no_chat(): void
    {
        $o = $this->making();
        $helper = User::factory()->create(['account_type' => AccountType::Craftsman]);
        app(Craftsmen::class)->share($o, $this->maker, $helper);

        $this->actingAs($helper)->get("/craftsman/jobs/{$o->id}")->assertInertia(fn (Assert $p) => $p->where('chat', null));
        $this->actingAs($helper)->post("/craftsman/jobs/{$o->id}/chat", ['text' => 'Hi'])->assertSessionHasErrors('job');
    }

    public function test_we_read_every_chat_answer_search_and_see_what_was_blocked(): void
    {
        $o = $this->making();
        $this->chat->send($o, $this->buyer, 'I want the polish darker');
        try {
            $this->chat->send($o, $this->maker, 'call 01812345678');
        } catch (StockException) {
        }

        $this->actingAs($this->staff)->get('/admin/chats?q=polish')->assertInertia(fn (Assert $p) => $p->component('admin/custom-chats')->has('chats', 1)->where('chats.0.blocked', 1)->has('found', 1)->has('blocks', 1));
        $this->get("/admin/chats/{$o->id}")->assertInertia(fn (Assert $p) => $p->component('admin/custom-chat')->where('chat.role', 'staff')->has('chat.messages', 1));
        $this->post("/admin/chats/{$o->id}", ['text' => 'We will make it darker'])->assertSessionHasNoErrors();
        $this->assertSame(1, $this->chat->unread($o->fresh(), 'buyer'));
    }

    public function test_repeated_attempts_show_up_against_a_person(): void
    {
        $o = $this->order();
        foreach (range(1, 3) as $i) {
            try {
                $this->chat->send($o, $this->buyer, "ring 0171234567$i");
            } catch (StockException) {
            }
        }

        $this->actingAs($this->staff)->get('/admin/chats')->assertInertia(fn (Assert $p) => $p->where('repeat.0.n', 3)->where('repeat.0.name', 'Buyer Bibi'));
    }

    public function test_the_owner_sends_an_offer_from_the_admin_page(): void
    {
        $o = $this->order();

        $this->actingAs($this->staff)->post("/admin/chats/{$o->id}/offer", ['price' => 9500, 'days' => 12, 'advance' => 30])->assertSessionHasNoErrors();

        $this->assertSame(['priced', '9500.00'], [$o->fresh()->status, (string) $o->fresh()->price]);
        $this->assertSame('offer', CustomChatMessage::first()->kind);
    }

    public function test_the_buyer_and_maker_cannot_open_the_admin_chats(): void
    {
        $o = $this->making();

        $this->actingAs($this->buyer)->get('/admin/chats')->assertRedirect('/');
        $this->actingAs($this->maker)->get("/admin/chats/{$o->id}")->assertRedirect('/');
        $this->actingAs($this->buyer)->post("/admin/chats/{$o->id}/offer", ['price' => 1, 'days' => 1])->assertRedirect('/');
    }
}
