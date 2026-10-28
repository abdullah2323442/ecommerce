<?php

/**
 * Every setting the owner can change from the Settings screen (spec ch 25: holiday / closed days, and the rules that
 * were only reachable in the database). Each key is read elsewhere with Setting::read(key, default); the defaults here
 * match those reads. Types: number (min, max, step), text (max), days (weekday numbers, 0 = Sunday), dates (list of Y-m-d).
 */
return [
    'orders' => [
        'title' => 'Orders and closed days',
        'hint' => 'Orders are always accepted. On a closed day or at night the buyer is only told when it will be looked at.',
        'items' => [
            'orders.weekly_off' => ['type' => 'days', 'label' => 'Closed every week on', 'default' => [5]],
            'orders.holidays' => ['type' => 'dates', 'label' => 'Holidays and other closed days', 'default' => []],
            'orders.night_from' => ['type' => 'number', 'label' => 'Night starts at (hour, 0 to 23)', 'default' => 22, 'min' => 0, 'max' => 23, 'step' => 1],
            'orders.night_to' => ['type' => 'number', 'label' => 'Night ends at (hour, 0 to 23)', 'default' => 8, 'min' => 0, 'max' => 23, 'step' => 1],
            'delivery.free_over' => ['type' => 'number', 'label' => 'Free delivery over (taka, 0 for never)', 'default' => 0, 'min' => 0, 'max' => 10000000, 'step' => 1],
            'delivery.advance_over' => ['type' => 'number', 'label' => 'Delivery charge paid first on orders of (taka, 0 for never)', 'default' => 0, 'min' => 0, 'max' => 10000000, 'step' => 1],
            'delivery.shop_district' => ['type' => 'text', 'label' => 'The district the shop is in', 'default' => 'Dhaka', 'max' => 40],
            'delivery.shop_area' => ['type' => 'text', 'label' => 'The shop\'s own area or thana (for the own-area courier rate)', 'default' => '', 'max' => 80, 'optional' => true],
            'delivery.round_mode' => ['type' => 'select', 'label' => 'How couriers round the weight', 'default' => 'up_kg', 'options' => ['up_kg' => 'Round up to a whole kilo', 'up_half' => 'Round up to half a kilo', 'exact' => 'Exact weight']],
            'delivery.outside_enabled' => ['type' => 'toggle', 'label' => 'Deliver outside that district', 'default' => true],
            'delivery.charge_inside' => ['type' => 'number', 'label' => 'Delivery charge inside that district (taka)', 'default' => 60, 'min' => 0, 'max' => 100000, 'step' => 1],
            'delivery.charge_outside' => ['type' => 'number', 'label' => 'Delivery charge outside that district (taka)', 'default' => 120, 'min' => 0, 'max' => 100000, 'step' => 1],
            'orders.edit_minutes' => ['type' => 'number', 'label' => 'Minutes a buyer can cancel an order by themselves', 'default' => 20, 'min' => 1, 'max' => 240, 'step' => 1],
            'orders.stale_hours' => ['type' => 'number', 'label' => 'Cancel an order nobody answered after (hours)', 'default' => 72, 'min' => 1, 'max' => 720, 'step' => 1],
            'notice.text' => ['type' => 'text', 'label' => 'Notice shown on every page (for example, an update tonight at 2 am)', 'default' => '', 'max' => 200, 'optional' => true],
            'notice.text_bn' => ['type' => 'text', 'label' => 'The same notice in Bangla', 'default' => '', 'max' => 200, 'optional' => true],
        ],
    ],
    'counter' => [
        'title' => 'Counter',
        'items' => [
            'shop.name' => ['type' => 'text', 'label' => 'Name printed on memos', 'default' => null, 'max' => 80],
            'counter.staff_discount_limit' => ['type' => 'number', 'label' => 'Discount staff can give without approval (%)', 'default' => 5, 'min' => 0, 'max' => 100, 'step' => 0.5],
            'approvals.expire_minutes' => ['type' => 'number', 'label' => 'A request for admin approval expires after (minutes)', 'default' => 10, 'min' => 1, 'max' => 1440, 'step' => 1],
            'flags.cancel_limit' => ['type' => 'number', 'label' => 'Late cancellations before a shop is flagged', 'default' => 3, 'min' => 1, 'max' => 100, 'step' => 1],
            'prescriptions.alert_minutes' => ['type' => 'number', 'label' => 'A prescription turns red if unseen for (minutes)', 'default' => 30, 'min' => 1, 'max' => 1440, 'step' => 1],
        ],
    ],
    'returns' => [
        'title' => 'Returns',
        'items' => [
            'returns.window_days' => ['type' => 'number', 'label' => 'Days a product can be returned', 'default' => 3, 'min' => 0, 'max' => 365, 'step' => 1],
            'returns.cash_percent' => ['type' => 'number', 'label' => 'Share of the price paid back in cash (%)', 'default' => 50, 'min' => 0, 'max' => 100, 'step' => 1],
            'returns.credit_warn_days' => ['type' => 'number', 'label' => 'Warn about unused credit after (days)', 'default' => 45, 'min' => 1, 'max' => 3650, 'step' => 1],
        ],
    ],
    'offers' => [
        'title' => 'Offers',
        'items' => [
            'offers.notify_percent' => ['type' => 'number', 'label' => 'Tell shops about an offer from this purchase discount (%)', 'default' => 20, 'min' => 0, 'max' => 100, 'step' => 0.5],
            'offers.min_margin' => ['type' => 'number', 'label' => 'Smallest profit an offer must keep (%)', 'default' => 3, 'min' => 0, 'max' => 100, 'step' => 0.5],
            'offers.alert_boxes' => ['type' => 'number', 'label' => 'Warn when an offer has this many boxes left', 'default' => 2, 'min' => 0, 'max' => 100000, 'step' => 1],
            'offers.grey_minutes' => ['type' => 'number', 'label' => 'A sold-out offer stays greyed for (minutes)', 'default' => 90, 'min' => 0, 'max' => 10080, 'step' => 1],
        ],
    ],
    'reports' => [
        'title' => 'Reports and tiers',
        'items' => [
            'report.month_start_day' => ['type' => 'number', 'label' => 'The monthly report starts on this day of the month (1 to 28)', 'default' => 1, 'min' => 1, 'max' => 28, 'step' => 1],
            'report.month_end_days' => ['type' => 'number', 'label' => 'Month-end window for tier tasks (last days of the month)', 'default' => 3, 'min' => 1, 'max' => 10, 'step' => 1],
            'ledger.overdue_days' => ['type' => 'number', 'label' => 'A customer is late when their oldest unpaid memo is older than (days)', 'default' => 30, 'min' => 1, 'max' => 365, 'step' => 1],
            'reports.lead_days' => ['type' => 'number', 'label' => 'Days it takes to get goods (for reorder advice)', 'default' => 7, 'min' => 0, 'max' => 90, 'step' => 1],
            'reports.cushion_days' => ['type' => 'number', 'label' => 'Spare days of stock to keep', 'default' => 3, 'min' => 0, 'max' => 90, 'step' => 1],
            'prices.warn_drop_percent' => ['type' => 'number', 'label' => 'Ask "are you sure?" when a new price is this many percent lower than the one before', 'default' => 70, 'min' => 10, 'max' => 99, 'step' => 5],
            'reports.season_days' => ['type' => 'number', 'label' => 'Days ahead to look at the same days last year for the seasonal forecast', 'default' => 30, 'min' => 7, 'max' => 90, 'step' => 1],
            'reports.slow_days' => ['type' => 'number', 'label' => 'A product is slow if unsold for (days)', 'default' => 60, 'min' => 1, 'max' => 1000, 'step' => 1],
        ],
    ],
    'trade' => [
        'title' => 'Bulk buyers',
        'items' => [
            'wholesale.moq' => ['type' => 'number', 'label' => 'Minimum per item for bulk buyers without their own (0 for none)', 'default' => 0, 'min' => 0, 'max' => 100000, 'step' => 1],
        ],
    ],
    'security' => [
        'title' => 'Security',
        'hint' => 'A staff account signed in on too many devices signs the oldest one out.',
        'items' => [
            'security.require_2fa_staff' => ['type' => 'toggle', 'label' => 'Staff and the owner must use two-step sign-in', 'default' => false],
            'services.cancel_hours' => ['type' => 'number', 'label' => 'Hours before a booked service after which a buyer can no longer cancel by themselves', 'default' => 12, 'min' => 0, 'max' => 168, 'step' => 1],
            'services.confirm_hours' => ['type' => 'number', 'label' => 'Hours a provider has to answer a booking request before it is declined by itself', 'default' => 24, 'min' => 1, 'max' => 168, 'step' => 1],
            'affiliate.days' => ['type' => 'number', 'label' => 'Days an affiliate link keeps its code on a visitor', 'default' => 30, 'min' => 1, 'max' => 365, 'step' => 1],
            'sms.bulk_daily_limit' => ['type' => 'number', 'label' => 'Most bulk texts that may go out in one day (0 for no limit)', 'default' => 2000, 'min' => 0, 'max' => 1000000, 'step' => 1],
            'sms.bulk_per_second' => ['type' => 'number', 'label' => 'Bulk texts sent each second', 'default' => 5, 'min' => 1, 'max' => 50, 'step' => 1],
            'archive.after_months' => ['type' => 'number', 'label' => 'Months after which old activity, sign-in records and read notices move to the archive (0 for never)', 'default' => 12, 'min' => 0, 'max' => 120, 'step' => 1],
            'security.session_limit' => ['type' => 'number', 'label' => 'Devices one staff account can be signed in on at once', 'default' => 3, 'min' => 1, 'max' => 20, 'step' => 1],
        ],
    ],
    'stock' => [
        'title' => 'Stock warnings',
        'hint' => 'When the stock page warns about many small lots, and how long a new printed price is marked.',
        'items' => [
            'stock.fragment_lots' => ['type' => 'number', 'label' => 'Warn when one medicine has this many lots', 'default' => 4, 'min' => 2, 'max' => 50, 'step' => 1],
            'stock.small_lot_units' => ['type' => 'number', 'label' => 'A lot this small or smaller is a small lot (units)', 'default' => 2, 'min' => 0, 'max' => 100, 'step' => 1],
            'stock.old_lot_days' => ['type' => 'number', 'label' => 'A lot older than this is an old lot (days)', 'default' => 90, 'min' => 7, 'max' => 720, 'step' => 1],
            'stock.mrp_mark_days' => ['type' => 'number', 'label' => 'Keep the "new printed price" mark for (days)', 'default' => 30, 'min' => 1, 'max' => 180, 'step' => 1],
        ],
    ],
    'tax' => [
        'title' => 'VAT',
        'hint' => 'Off at launch. When on, each memo line records the VAT inside its price. Set the rate on a company or a medicine.',
        'items' => [
            'vat.enabled' => ['type' => 'toggle', 'label' => 'Work out VAT on memo lines', 'default' => false],
        ],
    ],
    'preorder' => [
        'title' => 'Pre-order',
        'hint' => 'A share of the price asked up front when a buyer pre-orders. 0 asks for nothing.',
        'items' => [
            'preorder.advance_percent' => ['type' => 'number', 'label' => 'Advance share asked on a pre-order (%)', 'default' => 0, 'min' => 0, 'max' => 100, 'step' => 1],
        ],
    ],
    'drafts' => [
        'title' => 'Open drafts',
        'hint' => 'A counter draft nobody has touched for this long is asked about: return its goods to stock, or keep it.',
        'items' => [
            'drafts.stale_hours' => ['type' => 'number', 'label' => 'Ask about a draft nobody touched for (hours)', 'default' => 24, 'min' => 1, 'max' => 720, 'step' => 1],
            'drafts.ask_again_days' => ['type' => 'number', 'label' => 'After "Keep", ask again in (days)', 'default' => 2, 'min' => 1, 'max' => 30, 'step' => 1],
        ],
    ],
    'help' => [
        'title' => 'Help board',
        'hint' => 'How many posts one person may have at once, and how long a post stays before its owner is asked if it is still needed.',
        'items' => [
            'help.active_limit' => ['type' => 'number', 'label' => 'Posts one person may have waiting or open', 'default' => 3, 'min' => 1, 'max' => 20, 'step' => 1],
            'help.daily_limit' => ['type' => 'number', 'label' => 'Posts one person may make in a day', 'default' => 2, 'min' => 1, 'max' => 20, 'step' => 1],
            'help.ask_days' => ['type' => 'number', 'label' => 'Ask "Still needed?" after (days)', 'default' => 30, 'min' => 1, 'max' => 365, 'step' => 1],
            'help.grace_days' => ['type' => 'number', 'label' => 'Close the post if there is no answer after (days)', 'default' => 7, 'min' => 1, 'max' => 90, 'step' => 1],
            'help.reports_to_hide' => ['type' => 'number', 'label' => 'Take a post down after this many reports', 'default' => 3, 'min' => 1, 'max' => 50, 'step' => 1],
        ],
    ],
    'market' => [
        'title' => 'Market',
        'hint' => 'The price steps buyers can pick as a budget, and words that are not allowed on a listing.',
        'items' => [
            'deposit.enabled' => ['type' => 'toggle', 'label' => 'Ask sellers to keep a security deposit with us (off until trust is built)', 'default' => false],
            'deposit.amount' => ['type' => 'number', 'label' => 'The usual deposit a seller keeps with us (taka)', 'default' => 0, 'min' => 0, 'max' => 100000000, 'step' => 1],
            'market.reply_days' => ['type' => 'number', 'label' => 'Days a seller has to answer a commission statement', 'default' => 10, 'min' => 1, 'max' => 60, 'step' => 1],
            'market.auto_confirm_days' => ['type' => 'number', 'label' => 'Take a sent order as received after (days)', 'default' => 7, 'min' => 1, 'max' => 60, 'step' => 1],
            'market.return_days' => ['type' => 'number', 'label' => 'Days a buyer has to send a delivered order back', 'default' => 7, 'min' => 1, 'max' => 60, 'step' => 1],
            'market.trust_orders' => ['type' => 'number', 'label' => 'Finished orders with no complaint before a new seller is trusted', 'default' => 10, 'min' => 1, 'max' => 200, 'step' => 1],
            'market.trust_limit' => ['type' => 'number', 'label' => 'Dearest product a new seller may list (taka)', 'default' => 5000, 'min' => 1, 'max' => 10000000, 'step' => 100],
            'market.missing_limit' => ['type' => 'number', 'label' => '"Not received" in 90 days that puts a seller on our watch list', 'default' => 3, 'min' => 1, 'max' => 50, 'step' => 1],
            'market.answer_hours' => ['type' => 'number', 'label' => 'Hours a seller has to answer a question before it comes to us', 'default' => 24, 'min' => 1, 'max' => 240, 'step' => 1],
            'market.slow_limit' => ['type' => 'number', 'label' => 'Questions left too long in 60 days that puts a seller on our watch list', 'default' => 3, 'min' => 1, 'max' => 50, 'step' => 1],
            'market.offer_hours' => ['type' => 'number', 'label' => 'Hours a price offer waits for the seller, and an agreed price stays good', 'default' => 24, 'min' => 1, 'max' => 240, 'step' => 1],
            'market.pickup_remind_days' => ['type' => 'number', 'label' => 'Days after a seller says it is ready before the buyer is reminded to collect', 'default' => 3, 'min' => 1, 'max' => 30, 'step' => 1],
            'market.pickup_cancel_days' => ['type' => 'number', 'label' => 'More days after the reminder before an order nobody collected is cancelled', 'default' => 3, 'min' => 1, 'max' => 30, 'step' => 1],
            'market.budget_steps' => ['type' => 'text', 'label' => 'Budget steps in taka, with commas (for example 200,500,1000,5000)', 'default' => '200,500,1000,2000,5000,10000', 'max' => 120],
            'market.prohibited_words' => ['type' => 'text', 'label' => 'Words not allowed on a listing, with commas', 'default' => '', 'max' => 500, 'optional' => true],
        ],
    ],
    'custom' => [
        'title' => 'Made to order',
        'hint' => 'Wood and clothing made to a buyer\'s own measurements.',
        'items' => [
            'custom.advance_percent' => ['type' => 'number', 'label' => 'Advance on a made-to-order job (percent)', 'default' => 30, 'min' => 0, 'max' => 100, 'step' => 5],
            'custom.urgent_percent' => ['type' => 'number', 'label' => 'Extra charge for an urgent job (percent)', 'default' => 20, 'min' => 0, 'max' => 200, 'step' => 5],
            'custom.stale_months' => ['type' => 'number', 'label' => 'Ask whether saved measurements are still right after (months)', 'default' => 6, 'min' => 1, 'max' => 36, 'step' => 1],
            'custom.chat_wood' => ['type' => 'toggle', 'label' => 'Chat in wood jobs (used until a wood sector is opened; then its own choice decides)', 'default' => true],
            'custom.chat_clothing' => ['type' => 'toggle', 'label' => 'Chat in clothing jobs (used until a clothing sector is opened; then its own choice decides)', 'default' => true],
            'custom.reply_hours' => ['type' => 'number', 'label' => 'Hours a buyer waits for a chat answer before we are told', 'default' => 12, 'min' => 1, 'max' => 168, 'step' => 1],
            'custom.pickup_days' => ['type' => 'number', 'label' => 'Remind a buyer to collect finished work after (days)', 'default' => 7, 'min' => 1, 'max' => 60, 'step' => 1],
        ],
    ],
    'food' => [
        'title' => 'Food',
        'hint' => 'Fast food from local restaurants.',
        'items' => [
            'food.confirm_minutes' => ['type' => 'number', 'label' => 'Minutes a restaurant has to confirm before the buyer is asked whether to cancel', 'default' => 10, 'min' => 1, 'max' => 120, 'step' => 1],
            'food.road_factor' => ['type' => 'number', 'label' => 'When Google Maps is not connected, or does not answer, road distance is the straight line times this number', 'default' => 1.3, 'min' => 1, 'max' => 3, 'step' => 0.05],
            'food.eat_options' => ['type' => 'text', 'label' => 'Times offered for "I will eat there", in minutes, with commas', 'default' => '15,30,45,60', 'max' => 60],
        ],
    ],
    'gaming' => [
        'title' => 'Game top-up',
        'hint' => 'Where buyers send the money, and how long a top-up may take.',
        'items' => [
            'gaming.pay_numbers' => ['type' => 'text', 'label' => 'Where to send the money, like bkash: 01700000000, nagad: 01800000000', 'default' => '', 'max' => 200, 'optional' => true],
            'gaming.late_minutes' => ['type' => 'number', 'label' => 'Minutes a paid top-up may take before the buyer is told and can cancel for a refund', 'default' => 15, 'min' => 1, 'max' => 240, 'step' => 1],
        ],
    ],
    'habits' => [
        'title' => 'Order reminders',
        'hint' => 'A shop is nudged at the time of day it usually orders. A shop can turn it off for itself.',
        'items' => [
            'habits.enabled' => ['type' => 'toggle', 'label' => 'Remind each shop at the time it usually orders', 'default' => true],
            'buyagain.enabled' => ['type' => 'toggle', 'label' => 'Remind a buyer who takes the same medicine about every month', 'default' => true],
            'buyagain.min_buys' => ['type' => 'number', 'label' => 'Times a medicine must be bought before that reminder', 'default' => 3, 'min' => 3, 'max' => 12, 'step' => 1],
            'points.per_100' => ['type' => 'number', 'label' => 'Reward points a buyer earns for every 100 taka spent (0 for off)', 'default' => 0, 'min' => 0, 'max' => 1000, 'step' => 1],
            'points.worth' => ['type' => 'number', 'label' => 'What 100 reward points are worth as a token (taka)', 'default' => 10, 'min' => 1, 'max' => 10000, 'step' => 1],
            'points.min_redeem' => ['type' => 'number', 'label' => 'The least points that can be turned into a token', 'default' => 100, 'min' => 1, 'max' => 100000, 'step' => 1],
            'referral.reward' => ['type' => 'number', 'label' => 'Token for a friend who joins by a referral and orders (taka, 0 for off)', 'default' => 0, 'min' => 0, 'max' => 100000, 'step' => 1],
            'habits.min_orders' => ['type' => 'number', 'label' => 'Orders a shop needs before it gets that reminder', 'default' => 3, 'min' => 2, 'max' => 50, 'step' => 1],
        ],
    ],
];
