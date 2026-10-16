<?php

/**
 * What a staff role can be allowed to do (spec ch 26). The super admin is never limited by this list. Each key
 * maps to one gate in AppServiceProvider; new roles are built by switching these on and off one by one.
 */
return [
    'catalog' => [
        'counter' => 'Counter: sell, drafts, memos, quick notes',
        'orders' => 'Online orders and prescriptions',
        'returns' => 'Record returns (the admin approves)',
        'stock' => 'Stock, purchases, expiry and counts',
        'offers' => 'Offers',
        'returns_approve' => 'Approve returns and take a return without a memo',
        'approvals' => 'Answer "Need admin approval"',
        'reports' => 'Sales, profit and reports',
        'customers' => 'Customers, tiers and rates',
        'accounts' => 'Staff, roles and the activity log',
        'deliveries' => 'Deliver orders: my list, arrived, code or photo, money collected',
    ],

    // Starting roles (spec ch 26). A staff account with no role gets the first row's rights.
    'defaults' => [
        'Counter staff' => ['counter', 'orders', 'returns'],
        'Sub-admin' => ['counter', 'orders', 'returns', 'stock', 'offers', 'returns_approve', 'approvals', 'reports', 'customers'],
        'Order manager' => ['counter', 'orders', 'returns'],
        'Wholesale manager' => ['customers'],
        'Product manager' => ['stock', 'offers'],
        'Delivery person' => ['deliveries'],
    ],
];
