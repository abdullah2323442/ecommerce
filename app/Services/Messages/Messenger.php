<?php

namespace App\Services\Messages;

use App\Enums\AccountType;
use App\Http\Middleware\SetLocale;
use App\Mail\StatusMessage;
use App\Models\Invoice;
use App\Models\Notice;
use App\Services\Push\Notifications;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * Messages to customers about their orders and account (spec ch 23). One message can go by the inbox, email and SMS; which
 * channels each kind uses is the owner's choice (SMS costs money). A message that cannot be sent must never stop the order
 * it is about, so nothing here throws. Guests have no account, so they get no messages: their order page follows the order.
 */
class Messenger
{
    public function __construct(private SmsGateway $sms) {}

    // ------------------------------------------------------------------ order messages

    public function orderReceived(Invoice $order): void
    {
        $this->forOrder($order, 'order_received');
    }

    public function orderPicking(Invoice $order): void
    {
        $this->forOrder($order, 'order_picking');
    }

    /** Checked and ready; when some lines could not be supplied in full, a second, urgent message lists them. */
    public function orderChecked(Invoice $order): void
    {
        $this->forOrder($order, 'order_checked');

        $short = $order->lines()->with('medicine:id,brand_name,strength')->get()->filter(fn ($l) => in_array($l->verify_status, ['partial', 'unavailable'], true));
        if ($short->isNotEmpty()) {
            $this->forOrder($order, 'order_partial', ['items' => $short->map(fn ($l) => trim($l->medicine->brand_name.' '.$l->medicine->strength))->implode(', ')]);
        }
    }

    public function orderSent(Invoice $order, ?string $tracking = null): void
    {
        $this->forOrder($order, 'order_sent', array_filter(['tracking' => $tracking]));
    }

    public function orderArrived(Invoice $order): void
    {
        $this->forOrder($order, 'delivery_arrived');
    }

    public function orderDone(Invoice $order): void
    {
        if ($order->channel === 'online') {
            $this->forOrder($order, 'order_done');
        }
    }

    public function orderCancelled(Invoice $order, string $reason): void
    {
        $this->forOrder($order, 'order_cancelled', ['reason' => $reason]);
    }

    // ------------------------------------------------------------------ the one way in

    /**
     * @param  array<string, mixed>  $data
     */
    public function send(User $user, string $kind, array $data = [], ?string $link = null): void
    {
        try {
            $channels = $this->channelsFor($kind);
            // Outgoing words use the site's own language (Bangla), not the language of whoever clicked.
            $text = $this->text($kind, $data, SetLocale::DEFAULT);

            if (in_array('inbox', $channels, true)) {
                Notice::create(['user_id' => $user->id, 'kind' => $kind, 'body' => json_encode($data + array_filter(['link' => $link]), JSON_UNESCAPED_UNICODE)]);
            }

            if (in_array('email', $channels, true) && $this->realEmail($user)) {
                Mail::to($user->email)->queue(new StatusMessage($text, $link ? url($link) : null));
            }

            if (in_array('sms', $channels, true) && filled($user->phone)) {
                $this->sms->send((string) $user->phone, $text);
            }

            if (in_array('push', $channels, true)) {
                app(Notifications::class)->notify($user, (string) config('brand.name', config('app.name')), $text, $link);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** @return list<string> the channels this kind goes by, inbox first. The inbox cannot be turned off. */
    public function channelsFor(string $kind): array
    {
        $saved = (array) Setting::read('messages.channels', []);
        $channels = (array) ($saved[$kind] ?? config("messages.kinds.$kind.channels", ['inbox']));

        return array_values(array_unique(['inbox', ...array_intersect($channels, (array) config('messages.channels'))]));
    }

    /**
     * The words of a message in one language. The same sentences are on the inbox page, so they match everywhere.
     *
     * @param  array<string, mixed>  $d
     */
    public function text(string $kind, array $d, ?string $locale = null): string
    {
        $name = (string) ($d['name'] ?? '');

        return match ($kind) {
            'order_received' => __('We have received your order.', [], $locale),
            'order_picking' => __('Your products are being picked.', [], $locale),
            'order_checked' => __('Your order is checked and ready.', [], $locale),
            'order_partial' => __('Some products were not available: {items}', ['items' => (string) ($d['items'] ?? '')], $locale),
            'delivery_arrived' => __('Our delivery person has arrived with your order. Please have your code ready.', [], $locale),
            'order_sent' => ! empty($d['tracking']) ? __('Your order is on the way. Tracking: {tracking}', ['tracking' => (string) $d['tracking']], $locale) : __('Your order is on the way.', [], $locale),
            'order_done' => __('Your order is complete. Thank you.', [], $locale),
            'order_cancelled' => __('Your order was cancelled. {reason}', ['reason' => (string) ($d['reason'] ?? '')], $locale),
            'preorder_accepted' => __('Your pre-order for {name} is accepted. It should reach you within {n} days.', ['name' => $name, 'n' => (int) ($d['days'] ?? 0)], $locale),
            'preorder_declined' => __('Your pre-order for {name} was declined: {note}', ['name' => $name, 'note' => (string) ($d['note'] ?? '')], $locale),
            'preorder_extended' => __('The new time for your pre-order of {name} is {n} days.', ['name' => $name, 'n' => (int) ($d['days'] ?? 0)], $locale),
            'preorder_short' => (float) ($d['given'] ?? 0) <= 0
                ? __('None of the {name} you asked for came this time. You are first in line for the next lot.', ['name' => $name], $locale)
                : __('Only {given} of the {asked} {name} you asked for came.', ['given' => rtrim(rtrim(number_format((float) ($d['given'] ?? 0), 2, '.', ''), '0'), '.'), 'asked' => rtrim(rtrim(number_format((float) ($d['asked'] ?? 0), 2, '.', ''), '0'), '.'), 'name' => $name], $locale),
            'preorder_cancelled' => __('Sorry, your pre-order for {name} could not be supplied: {note}', ['name' => $name, 'note' => (string) ($d['note'] ?? '')], $locale),
            'preorder_arrived' => ! empty($d['short'])
                ? __('Your pre-order for {name} has arrived, but only some of it came.', ['name' => $name], $locale)
                : __('Your pre-order for {name} has arrived.', ['name' => $name], $locale),
            'mpo_bid_accepted' => __('Your offer for {name} was accepted. It is ordered at your price.', ['name' => $name], $locale),
            'mpo_bid_declined' => __('Your offer for {name} was not taken.', ['name' => $name], $locale),
            'mpo_bid_new' => __('A new offer came for {name}.', ['name' => $name], $locale),
            'shop_mistake' => __('A return was counted as your own mistake: {reason}. Please check the goods before you order.', ['reason' => (string) ($d['reason'] ?? '')], $locale),
            'repeat_mistake' => __('The same mistake ({reason}) has now happened three times in 90 days. Please take more care.', ['reason' => (string) ($d['reason'] ?? '')], $locale),
            'staff_mistake' => __('A return on memo {memo} was counted as your mistake. Please check each sale carefully.', ['memo' => (string) ($d['memo'] ?? '')], $locale),
            'mpo_stale' => __('Your product {name} is not selling. Would you like to raise the percentage yourself?', ['name' => $name], $locale),
            'listing_question_new' => __('A buyer asked about "{name}". Please answer in your questions.', ['name' => $name], $locale),
            'listing_question_answered' => __('Your question about "{name}" was answered.', ['name' => $name], $locale),
            'listing_question_late' => __('A question about "{name}" was left too long, so it went to us. Please answer questions sooner.', ['name' => $name], $locale),
            'price_offer_new' => __('A buyer offered a price for "{name}". Open your offers to answer. It goes after a day.', ['name' => $name], $locale),
            'price_offer_accepted' => __('The seller agreed to your price for "{name}". Order it before it runs out.', ['name' => $name], $locale),
            'price_offer_countered' => __('The seller named another price for "{name}". Have a look.', ['name' => $name], $locale),
            'price_offer_declined' => __('The seller did not take your price for "{name}".', ['name' => $name], $locale),
            'price_offer_expired' => __('Your price offer for "{name}" ran out.', ['name' => $name], $locale),
            'cart_left' => __('You left items in your cart ({n}). They are waiting for you.', ['n' => $name], $locale),
            'wishlist_drop' => __('"{name}" is cheaper now: {now} instead of {was}.', ['name' => $name, 'now' => (string) ($d['now'] ?? ''), 'was' => (string) ($d['was'] ?? '')], $locale),
            'listing_low_stock' => __('"{name}" is running low. Add more so you do not miss orders.', ['name' => $name], $locale),
            'shop_new_product' => __('{shop} has a new product: "{name}".', ['shop' => (string) ($d['shop'] ?? ''), 'name' => $name], $locale),
            'catalog_decided' => ! empty($d['note'])
                ? __('We could not add "{name}" to the catalog: {note}', ['name' => $name, 'note' => (string) $d['note']], $locale)
                : __('"{name}" is in the catalog now. You can list it.', ['name' => $name], $locale),
            'market_pickup_ready' => __('Your order for "{name}" is ready. Collect it from: {where}', ['name' => $name, 'where' => (string) ($d['where'] ?? '')], $locale),
            'market_pickup_reminder' => __('Please collect your order for "{name}" from: {where}. If nobody comes, it will be cancelled.', ['name' => $name, 'where' => (string) ($d['where'] ?? '')], $locale),
            'powers_lent' => __('You can now also do: {name}. This lasts until {until}.', ['name' => $name, 'until' => (string) ($d['until'] ?? '')], $locale),
            'market_order_new' => __('A new order for "{name}". Open your orders to answer it.', ['name' => $name], $locale),
            'support_replied' => __('We answered your request {name}. Open it to read the answer.', ['name' => $name], $locale),
            'market_download_ready' => __('Your download for "{name}" is ready. Open your order to get it.', ['name' => $name], $locale),
            'market_order_accepted' => __('Your order for "{name}" was accepted.', ['name' => $name], $locale),
            'market_order_sent' => __('Your order for "{name}" is on the way.', ['name' => $name], $locale),
            'market_order_cancelled' => __('The order for "{name}" was cancelled.', ['name' => $name], $locale),
            'market_statement' => __('Your statement for {name}: sales were {sales} and our commission is {commission}. Please answer Paid or Problem.', ['name' => $name, 'sales' => number_format((float) ($d['sales'] ?? 0), 2), 'commission' => number_format((float) ($d['commission'] ?? 0), 2)], $locale),
            'market_suspended' => __('Selling is switched off because the statement for {name} was not answered. Answer it and it comes back.', ['name' => $name], $locale),
            'market_case_new' => __('A buyer has a complaint or a return for "{name}". Open your orders to answer it.', ['name' => $name], $locale),
            'market_case_answered' => __('There is an answer about "{name}". Open it to read.', ['name' => $name], $locale),
            'booking_new' => __('Someone asked to book "{name}" on {when}. Open Services to answer.', ['name' => $name, 'when' => (string) ($d['when'] ?? '')], $locale),
            'booking_answer' => match ($d['state'] ?? '') {
                'confirmed' => __('Your booking for "{name}" on {when} is confirmed.', ['name' => $name, 'when' => (string) ($d['when'] ?? '')], $locale),
                'declined' => __('Your booking for "{name}" on {when} was declined. {note}', ['name' => $name, 'when' => (string) ($d['when'] ?? ''), 'note' => (string) ($d['note'] ?? '')], $locale),
                'cancelled' => __('The booking for "{name}" on {when} was cancelled.', ['name' => $name, 'when' => (string) ($d['when'] ?? '')], $locale),
                default => __('A reminder: "{name}" is on {when}.', ['name' => $name, 'when' => (string) ($d['when'] ?? '')], $locale),
            },
            'market_deposit' => match ($d['name'] ?? '') {
                'returned' => __('We gave back {amount} of your deposit.', ['amount' => (string) ($d['amount'] ?? '')], $locale),
                'deducted' => __('{amount} was taken from your deposit: {note}', ['amount' => (string) ($d['amount'] ?? ''), 'note' => (string) ($d['note'] ?? '')], $locale),
                default => __('We received {amount} as your deposit.', ['amount' => (string) ($d['amount'] ?? '')], $locale),
            },
            'market_penalty' => match ($d['name'] ?? '') {
                'listing_removed' => __('One of your listings was taken down: {note}', ['note' => (string) ($d['note'] ?? '')], $locale),
                'suspended' => __('Your selling is switched off: {note}', ['note' => (string) ($d['note'] ?? '')], $locale),
                'withheld' => __('Your payouts are on hold: {note}', ['note' => (string) ($d['note'] ?? '')], $locale),
                default => __('A warning about your selling: {note}', ['note' => (string) ($d['note'] ?? '')], $locale),
            },
            'listing_report_done' => ! empty($d['removed'])
                ? __('Thank you for your report about "{name}". We took it down. {note}', ['name' => $name, 'note' => (string) ($d['note'] ?? '')], $locale)
                : __('Thank you for your report about "{name}". We looked and left it up. {note}', ['name' => $name, 'note' => (string) ($d['note'] ?? '')], $locale),
            'quote_answered' => __('We have a price for "{name}". Open your quotations to read it.', ['name' => $name], $locale),
            'custom_priced' => __('Your made-to-order job {name} has a price. Check the measurements and confirm.', ['name' => $name], $locale),
            'custom_started' => __('Work on {name} has started.', ['name' => $name], $locale),
            'custom_trial' => __('{name} is ready for you to try on.', ['name' => $name], $locale),
            'custom_done' => __('{name} is ready. Please come and collect it.', ['name' => $name], $locale),
            'custom_cancelled' => __('{name} was cancelled by us. Open it to see why.', ['name' => $name], $locale),
            'custom_answered' => __('We have answered about {name}. Open it to read.', ['name' => $name], $locale),
            'custom_pickup' => __('{name} has been ready for some days. Please collect it.', ['name' => $name], $locale),
            'custom_late' => __('{name} is taking longer than promised. We are sorry, and we are looking into it.', ['name' => $name], $locale),
            'craftsman_job' => __('A job is yours: {name}. Open it to see the measurements.', ['name' => $name], $locale),
            'craftsman_wage_ask' => __('Please look at {name} and tell us what the wage should be.', ['name' => $name], $locale),
            'craftsman_reminder' => __('Please enter how far {name} has got, with a photo.', ['name' => $name], $locale),
            'custom_chat' => __('There is a new message about {name}. Open the chat to read it.', ['name' => $name], $locale),
            'craftsman_chat' => __('There is a new message about {name}. Open the chat to read it.', ['name' => $name], $locale),
            'food_order_new' => __('A new food order {name}. Open your orders to confirm it.', ['name' => $name], $locale),
            'food_update' => match ($d['what'] ?? '') {
                'confirmed' => __('Your order {name} is confirmed and cooking. About {n} minutes.', ['name' => $name, 'n' => (int) ($d['minutes'] ?? 0)], $locale),
                'packing' => __('Your order {name} is being packed.', ['name' => $name], $locale),
                'out' => __('Your order {name} is out for delivery.', ['name' => $name], $locale),
                'ready' => __('Your order {name} is ready. Come and eat.', ['name' => $name], $locale),
                default => __('Your order {name} was delivered.', ['name' => $name], $locale),
            },
            'food_unconfirmed' => __('Your order {name} has not been approved yet. Do you want to cancel it?', ['name' => $name], $locale),
            'food_cancelled' => __('The food order {name} was cancelled.', ['name' => $name], $locale),
            'food_complaint' => __('The order {name} has a complaint with a photo. Open your orders to read it.', ['name' => $name], $locale),
            'game_done' => __('Your top-up {name} is done. The {package} for {game} is in your account.', ['name' => $name, 'package' => (string) ($d['package'] ?? ''), 'game' => (string) ($d['game'] ?? '')], $locale),
            'game_failed' => __('Your top-up {name} did not go through. Your money will be refunded.', ['name' => $name], $locale),
            'game_late' => __('Your top-up {name} is taking longer than it should. You can wait, or cancel it for a refund.', ['name' => $name], $locale),
            'game_refunded' => __('Your money for the top-up {name} has been refunded.', ['name' => $name], $locale),
            'listing_approved' => __('Your listing "{name}" is published.', ['name' => $name], $locale),
            'listing_rejected' => __('Your listing "{name}" was not published. {note}', ['name' => $name, 'note' => (string) ($d['note'] ?? '')], $locale),
            'help_approved' => __('Your post "{name}" is published on the Help board.', ['name' => $name], $locale),
            'help_rejected' => __('Your post "{name}" was not published. {note}', ['name' => $name, 'note' => (string) ($d['note'] ?? '')], $locale),
            'help_still_needed' => __('Your post "{name}" has been up for a month. Is it still needed? If you do not answer, it will close.', ['name' => $name], $locale),
            'buy_again' => __('You usually buy {name} about every {days} days. Time to get it again?', ['name' => $name, 'days' => (int) ($d['days'] ?? 0)], $locale),
            'question_answered' => __('Your question about {name} was answered.', ['name' => $name], $locale),
            'order_habit' => __('This is about the time you usually order. Place your orders at night and we will have everything packed the same night.', [], $locale),
            'tier_up' => __('Congratulations! You are now in tier {name}.', ['name' => $name], $locale),
            'application_approved' => __('Your business account is open. Welcome, {name}.', ['name' => $name], $locale),
            default => '',
        };
    }

    // ------------------------------------------------------------------ helpers

    /** @param array<string, mixed> $data */
    private function forOrder(Invoice $order, string $kind, array $data = []): void
    {
        $order->loadMissing('customer');

        if (! $order->customer) {
            return;
        }

        $this->send($order->customer, $kind, $data, $this->orderLink($order));
    }

    /** Where the customer opens the order: their own portal, or the private link of a plain account's order. */
    private function orderLink(Invoice $order): ?string
    {
        return match ($order->customer?->account_type) {
            AccountType::Paikari => '/paikari/orders/'.$order->id,
            AccountType::Wholesaler => '/wholesale/orders/'.$order->id,
            default => $order->public_token ? '/orders/'.$order->public_token : null,
        };
    }

    private function realEmail(User $user): bool
    {
        return filled($user->email) && ! str_ends_with((string) $user->email, '.invalid');
    }
}
