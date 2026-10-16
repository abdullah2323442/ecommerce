<?php

/*
 * Pricing rules that the spec leaves open. Each value is a deliberate default, not a decision.
 * Confirm with Siam, then change here. See docs/overview.md §7.4 (OQ-1, OQ-3, OQ-5).
 */
return [
    // OQ-5: rounding for taka amounts. Default: round half-up to 2 decimals.
    'rounding' => 'half_up_2dp',

    // OQ-1: order of discount steps after the base price. Default: quantity, then token, then credit.
    'discount_order' => ['quantity', 'token', 'return_credit'],

    // OQ-3: at the counter, a per-medicine % versus the customer's manual rate.
    // Default 'manual_wins': a manual rate always beats an invoice-level or per-medicine % typed at the counter.
    'counter_priority' => 'manual_wins',
];
