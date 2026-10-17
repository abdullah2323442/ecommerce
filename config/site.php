<?php

/**
 * What the owner can change from Admin > Site design. Everything here is only the starting point: a saved value in the
 * `site_settings` table wins, and an empty value falls back to config/brand.php (the older .env values) or to this file.
 * Texts are {en, bn} pairs; a missing Bangla text shows the English one.
 */
return [
    'brand' => [
        'name' => null,            // empty: config('brand.name')
        'name_bn' => null,
        'tagline' => ['en' => null, 'bn' => null],
        'logo' => null,            // a stored file; empty: the drop icon
        'favicon' => null,
        'theme_color' => null,     // the colour of the installed app's bar and icon background; empty: teal
    ],

    'contact' => [
        'phone' => null, 'email' => null, 'address' => null, 'whatsapp' => null, 'messenger' => null, 'drug_license' => null,
        'hours' => ['en' => null, 'bn' => null],
    ],

    'social' => ['facebook' => null, 'instagram' => null, 'youtube' => null, 'tiktok' => null],

    'footer' => [
        'about' => ['en' => null, 'bn' => null],
        'copyright' => ['en' => null, 'bn' => null],
        // Each link: label (English; Bangla optional), url (starts with / or https://), enabled. Shown in the second footer column.
        'links' => [
            ['label' => 'Market', 'label_bn' => null, 'url' => '/market', 'enabled' => true],
            ['label' => 'Made to order', 'label_bn' => null, 'url' => '/custom', 'enabled' => true],
            ['label' => 'Food', 'label_bn' => null, 'url' => '/food', 'enabled' => true],
            ['label' => 'Game top-up', 'label_bn' => null, 'url' => '/gaming', 'enabled' => true],
            ['label' => 'Wallet', 'label_bn' => null, 'url' => '/wallet', 'enabled' => true],
            ['label' => 'Help board', 'label_bn' => null, 'url' => '/help', 'enabled' => true],
            ['label' => 'Questions and answers', 'label_bn' => null, 'url' => '/faq', 'enabled' => true],
            ['label' => 'Return policy', 'label_bn' => null, 'url' => '/return-policy', 'enabled' => true],
            ['label' => 'Contact us', 'label_bn' => null, 'url' => '/support/new', 'enabled' => true],
            ['label' => 'Terms of service', 'label_bn' => null, 'url' => '/terms', 'enabled' => true],
            ['label' => 'Privacy policy', 'label_bn' => null, 'url' => '/privacy', 'enabled' => true],
        ],
    ],

    'home' => [
        'hero_title' => ['en' => null, 'bn' => null],
        'hero_text' => ['en' => null, 'bn' => null],
        // The page built in the home page builder: an ordered list of blocks. Empty: the standard page (see App\Services\Site\HomeBlocks::defaults).
        'blocks' => null,
        // The shop cards on the home page, in this order. status: open (a link) or soon (a label). A hidden card is not drawn at all.
        'sections' => [
            ['key' => 'pharmacy', 'enabled' => true, 'status' => 'open'],
            ['key' => 'food', 'enabled' => true, 'status' => 'open'],
            ['key' => 'games', 'enabled' => true, 'status' => 'open'],
            ['key' => 'custom', 'enabled' => true, 'status' => 'open'],
            ['key' => 'market', 'enabled' => true, 'status' => 'open'],
            ['key' => 'help', 'enabled' => true, 'status' => 'open'],
        ],
    ],

    // A slim bar above every page: a closed day, an offer. Empty text or off: nothing is drawn.
    'announcement' => ['enabled' => false, 'text' => ['en' => null, 'bn' => null], 'link' => null],

    'seo' => ['description' => ['en' => null, 'bn' => null]],

    // Where each home card goes when it is open (a fixed list: a card can never point anywhere else).
    'section_links' => ['pharmacy' => '/medicines', 'food' => '/food', 'games' => '/gaming', 'custom' => '/custom', 'market' => '/market', 'help' => '/help'],
];
