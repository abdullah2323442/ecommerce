<?php

/**
 * One place for the shop's name and contact details (the name is not decided yet, so nothing else hard-codes it).
 * Every value is optional: a link or line only appears on the site when its value is set.
 */
return [
    'name' => env('BRAND_NAME', env('APP_NAME', "Siam's Aqua")),
    'name_bn' => env('BRAND_NAME_BN'),
    'logo' => env('BRAND_LOGO'),
    'phone' => env('BRAND_PHONE'),
    'email' => env('BRAND_EMAIL'),
    'address' => env('BRAND_ADDRESS'),
    // Shown as a chat button when set: digits only with the country code (8801XXXXXXXXX), and the page name of the Messenger page.
    'whatsapp' => env('BRAND_WHATSAPP'),
    'messenger' => env('BRAND_MESSENGER'),
    // The drug licence number, shown in the footer once it is real (spec ch 27: building trust).
    'drug_license' => env('BRAND_DRUG_LICENSE'),
];
