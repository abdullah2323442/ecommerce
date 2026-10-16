<?php

return [
    // Spec ch 26: maximum devices an account may use in 30 days before a login is flagged.
    'device_limit' => (int) env('SECURITY_DEVICE_LIMIT', 3),
];
