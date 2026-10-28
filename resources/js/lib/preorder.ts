/** The words for each message a customer gets, built from the small JSON the server stores. The same sentences go out by email and SMS. */
export interface PreorderNotice {
    id: number;
    kind: string;
    body: string;
    importance?: string;
    link?: string | null;
    read?: boolean;
    when?: string;
}

type T = (key: string, params?: Record<string, string | number>) => string;

interface Body {
    name?: string;
    amount?: string;
    when?: string;
    state?: string;
    days?: number;
    note?: string;
    short?: boolean;
    items?: string;
    reason?: string;
    tracking?: string;
    shop?: string;
    where?: string;
    was?: string;
    now?: string;
    until?: string;
    given?: number;
    asked?: number;
    memo?: string;
}

export function noticeText(n: PreorderNotice, t: T): string {
    let b: Body = {};
    try {
        const parsed = JSON.parse(n.body);
        b = typeof parsed === 'object' && parsed !== null ? parsed : { name: String(parsed) };
    } catch {
        // Older messages stored just the name.
        b = { name: n.body };
    }
    const name = b.name ?? '';

    switch (n.kind) {
        case 'delivery_arrived':
            return t('Our delivery person has arrived with your order. Please have your code ready.');
        case 'order_received':
            return t('We have received your order.');
        case 'order_picking':
            return t('Your products are being picked.');
        case 'order_checked':
            return t('Your order is checked and ready.');
        case 'order_partial':
            return t('Some products were not available: {items}', { items: b.items ?? '' });
        case 'order_sent':
            return b.tracking ? t('Your order is on the way. Tracking: {tracking}', { tracking: b.tracking }) : t('Your order is on the way.');
        case 'order_done':
            return t('Your order is complete. Thank you.');
        case 'order_cancelled':
            return t('Your order was cancelled. {reason}', { reason: b.reason ?? '' });
        case 'mpo_bid_accepted':
            return t('Your offer for {name} was accepted. It is ordered at your price.', { name });
        case 'mpo_bid_declined':
            return t('Your offer for {name} was not taken.', { name });
        case 'mpo_bid_new':
            return t('A new offer came for {name}.', { name });
        case 'shop_mistake':
            return t('A return was counted as your own mistake: {reason}. Please check the goods before you order.', { reason: b.reason ?? '' });
        case 'repeat_mistake':
            return t('The same mistake ({reason}) has now happened three times in 90 days. Please take more care.', { reason: b.reason ?? '' });
        case 'staff_mistake':
            return t('A return on memo {memo} was counted as your mistake. Please check each sale carefully.', { memo: b.memo ?? '' });
        case 'mpo_stale':
            return t('Your product {name} is not selling. Would you like to raise the percentage yourself?', { name });
        case 'listing_question_new':
            return t('A buyer asked about "{name}". Please answer in your questions.', { name });
        case 'listing_question_answered':
            return t('Your question about "{name}" was answered.', { name });
        case 'listing_question_late':
            return t('A question about "{name}" was left too long, so it went to us. Please answer questions sooner.', { name });
        case 'price_offer_new':
            return t('A buyer offered a price for "{name}". Open your offers to answer. It goes after a day.', { name });
        case 'price_offer_accepted':
            return t('The seller agreed to your price for "{name}". Order it before it runs out.', { name });
        case 'price_offer_countered':
            return t('The seller named another price for "{name}". Have a look.', { name });
        case 'price_offer_declined':
            return t('The seller did not take your price for "{name}".', { name });
        case 'price_offer_expired':
            return t('Your price offer for "{name}" ran out.', { name });
        case 'cart_left':
            return t('You left items in your cart ({n}). They are waiting for you.', { n: name });
        case 'wishlist_drop':
            return t('"{name}" is cheaper now: {now} instead of {was}.', { name, now: b.now ?? '', was: b.was ?? '' });
        case 'listing_low_stock':
            return t('"{name}" is running low. Add more so you do not miss orders.', { name });
        case 'shop_new_product':
            return t('{shop} has a new product: "{name}".', { shop: String(b.shop ?? ''), name });
        case 'catalog_decided':
            return b.note ? t('We could not add "{name}" to the catalog: {note}', { name, note: b.note }) : t('"{name}" is in the catalog now. You can list it.', { name });
        case 'market_pickup_ready':
            return t('Your order for "{name}" is ready. Collect it from: {where}', { name, where: b.where ?? '' });
        case 'market_pickup_reminder':
            return t('Please collect your order for "{name}" from: {where}. If nobody comes, it will be cancelled.', { name, where: b.where ?? '' });
        case 'powers_lent':
            return t('You can now also do: {name}. This lasts until {until}.', { name, until: b.until ?? '' });
        case 'market_order_new':
            return t('A new order for "{name}". Open your orders to answer it.', { name });
        case 'market_order_accepted':
            return t('Your order for "{name}" was accepted.', { name });
        case 'market_order_sent':
            return t('Your order for "{name}" is on the way.', { name });
        case 'market_order_cancelled':
            return t('The order for "{name}" was cancelled.', { name });
        case 'market_statement':
            return t('Your statement for {name}: sales were {sales} and our commission is {commission}. Please answer Paid or Problem.', { name, sales: Number((b as { sales?: number }).sales ?? 0).toFixed(2), commission: Number((b as { commission?: number }).commission ?? 0).toFixed(2) });
        case 'market_suspended':
            return t('Selling is switched off because the statement for {name} was not answered. Answer it and it comes back.', { name });
        case 'market_case_new':
            return t('A buyer has a complaint or a return for "{name}". Open your orders to answer it.', { name });
        case 'market_case_answered':
            return t('There is an answer about "{name}". Open it to read.', { name });
        case 'booking_new':
            return t('Someone asked to book "{name}" on {when}. Open Services to answer.', { name, when: String(b.when ?? '') });
        case 'booking_answer': {
            const when = String(b.when ?? '');
            return b.state === 'confirmed'
                ? t('Your booking for "{name}" on {when} is confirmed.', { name, when })
                : b.state === 'declined'
                  ? t('Your booking for "{name}" on {when} was declined. {note}', { name, when, note: String(b.note ?? '') })
                  : b.state === 'cancelled'
                    ? t('The booking for "{name}" on {when} was cancelled.', { name, when })
                    : t('A reminder: "{name}" is on {when}.', { name, when });
        }
        case 'market_deposit': {
            const amount = String(b.amount ?? '');
            return b.name === 'returned'
                ? t('We gave back {amount} of your deposit.', { amount })
                : b.name === 'deducted'
                  ? t('{amount} was taken from your deposit: {note}', { amount, note: String(b.note ?? '') })
                  : t('We received {amount} as your deposit.', { amount });
        }
        case 'market_penalty': {
            const note = String(b.note ?? '');
            return b.name === 'listing_removed'
                ? t('One of your listings was taken down: {note}', { note })
                : b.name === 'suspended'
                  ? t('Your selling is switched off: {note}', { note })
                  : b.name === 'withheld'
                    ? t('Your payouts are on hold: {note}', { note })
                    : t('A warning about your selling: {note}', { note });
        }
        case 'listing_report_done':
            return (b as { removed?: boolean }).removed
                ? t('Thank you for your report about "{name}". We took it down. {note}', { name, note: b.note ?? '' })
                : t('Thank you for your report about "{name}". We looked and left it up. {note}', { name, note: b.note ?? '' });
        case 'quote_answered':
            return t('We have a price for "{name}". Open your quotations to read it.', { name });
        case 'custom_priced':
            return t('Your made-to-order job {name} has a price. Check the measurements and confirm.', { name });
        case 'custom_started':
            return t('Work on {name} has started.', { name });
        case 'custom_trial':
            return t('{name} is ready for you to try on.', { name });
        case 'custom_done':
            return t('{name} is ready. Please come and collect it.', { name });
        case 'custom_cancelled':
            return t('{name} was cancelled by us. Open it to see why.', { name });
        case 'custom_answered':
            return t('We have answered about {name}. Open it to read.', { name });
        case 'custom_pickup':
            return t('{name} has been ready for some days. Please collect it.', { name });
        case 'custom_late':
            return t('{name} is taking longer than promised. We are sorry, and we are looking into it.', { name });
        case 'craftsman_job':
            return t('A job is yours: {name}. Open it to see the measurements.', { name });
        case 'craftsman_wage_ask':
            return t('Please look at {name} and tell us what the wage should be.', { name });
        case 'craftsman_reminder':
            return t('Please enter how far {name} has got, with a photo.', { name });
        case 'custom_chat':
            return t('There is a new message about {name}. Open the chat to read it.', { name });
        case 'craftsman_chat':
            return t('There is a new message about {name}. Open the chat to read it.', { name });
        case 'food_order_new':
            return t('A new food order {name}. Open your orders to confirm it.', { name });
        case 'food_update': {
            const what = (b as { what?: string }).what;
            return what === 'confirmed'
                ? t('Your order {name} is confirmed and cooking. About {n} minutes.', { name, n: Number((b as { minutes?: number }).minutes ?? 0) })
                : what === 'packing'
                  ? t('Your order {name} is being packed.', { name })
                  : what === 'out'
                    ? t('Your order {name} is out for delivery.', { name })
                    : what === 'ready'
                      ? t('Your order {name} is ready. Come and eat.', { name })
                      : t('Your order {name} was delivered.', { name });
        }
        case 'food_unconfirmed':
            return t('Your order {name} has not been approved yet. Do you want to cancel it?', { name });
        case 'food_cancelled':
            return t('The food order {name} was cancelled.', { name });
        case 'food_complaint':
            return t('The order {name} has a complaint with a photo. Open your orders to read it.', { name });
        case 'game_done':
            return t('Your top-up {name} is done. The {package} for {game} is in your account.', { name, package: String((b as { package?: string }).package ?? ''), game: String((b as { game?: string }).game ?? '') });
        case 'game_failed':
            return t('Your top-up {name} did not go through. Your money will be refunded.', { name });
        case 'game_late':
            return t('Your top-up {name} is taking longer than it should. You can wait, or cancel it for a refund.', { name });
        case 'game_refunded':
            return t('Your money for the top-up {name} has been refunded.', { name });
        case 'listing_approved':
            return t('Your listing "{name}" is published.', { name });
        case 'listing_rejected':
            return t('Your listing "{name}" was not published. {note}', { name, note: b.note ?? '' });
        case 'help_approved':
            return t('Your post "{name}" is published on the Help board.', { name });
        case 'help_rejected':
            return t('Your post "{name}" was not published. {note}', { name, note: b.note ?? '' });
        case 'help_still_needed':
            return t('Your post "{name}" has been up for a month. Is it still needed? If you do not answer, it will close.', { name });
        case 'buy_again':
            return t('You usually buy {name} about every {days} days. Time to get it again?', { name, days: Number((b as { days?: number }).days ?? 0) });
        case 'question_answered':
            return t('Your question about {name} was answered.', { name });
        case 'order_habit':
            return t('This is about the time you usually order. Place your orders at night and we will have everything packed the same night.');
        case 'tier_up':
            return t('Congratulations! You are now in tier {name}.', { name });
        case 'application_approved':
            return t('Your business account is open. Welcome, {name}.', { name });
        case 'preorder_accepted':
            return t('Your pre-order for {name} is accepted. It should reach you within {n} days.', { name, n: b.days ?? 0 });
        case 'preorder_declined':
            return t('Your pre-order for {name} was declined: {note}', { name, note: b.note ?? '' });
        case 'preorder_extended':
            return t('The new time for your pre-order of {name} is {n} days.', { name, n: b.days ?? 0 });
        case 'preorder_short':
            return (b.given ?? 0) <= 0 ? t('None of the {name} you asked for came this time. You are first in line for the next lot.', { name }) : t('Only {given} of the {asked} {name} you asked for came.', { given: b.given ?? 0, asked: b.asked ?? 0, name });
        case 'preorder_cancelled':
            return t('Sorry, your pre-order for {name} could not be supplied: {note}', { name, note: b.note ?? '' });
        case 'preorder_arrived':
            return b.short ? t('Your pre-order for {name} has arrived, but only some of it came.', { name }) : t('Your pre-order for {name} has arrived.', { name });
        default:
            return n.body;
    }
}

export const preorderState = (status: string, late: boolean) =>
    late ? 'Past its time' : status === 'requested' ? 'Waiting for our answer' : status === 'accepted' ? 'Accepted' : status === 'declined' ? 'Declined' : status === 'fulfilled' ? 'Arrived' : 'Cancelled';
