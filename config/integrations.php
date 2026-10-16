<?php

/**
 * The outside services the owner can connect from Admin > Connections (spec ch 28: nothing hard-coded, no developer needed).
 * `ready` says whether the code that talks to that service is built and can be switched on; the rest can have their keys saved ahead of time but cannot
 * be turned on yet. Fields marked `secret` are encrypted when saved and are never shown again, only "set" and the last four characters.
 * `single` means only one service of that kind can be on at a time (one text service, one map).
 */
return [
    'types' => [
        'sms' => ['label' => 'Text messages', 'hint' => 'Order codes, delivery codes and messages to buyers.', 'single' => true],
        'social' => ['label' => 'Sign in with', 'hint' => 'Let people sign in with an account they already have.', 'single' => false],
        'payment' => ['label' => 'Online payment', 'hint' => 'Take money online. Cash on delivery and sending money by hand stay available.', 'single' => false],
        'courier' => ['label' => 'Courier', 'hint' => 'Book a parcel and follow it. Charges you type by hand keep working without this.', 'single' => false],
        'map' => ['label' => 'Maps and location', 'hint' => 'Fill the address from the buyer\'s location, and show a map pin.', 'single' => true],
    ],

    'providers' => [
        'sms:http' => [
            'name' => 'Any text service with a web address (HTTP)', 'ready' => true, 'help' => 'Works with most Bangladeshi text services: give the address they gave you, and the names of the two fields.',
            'fields' => [
                ['name' => 'url', 'label' => 'Address (URL)', 'type' => 'url', 'required' => true, 'help' => 'For example https://api.example.com/send'],
                ['name' => 'method', 'label' => 'Send with', 'type' => 'select', 'options' => ['GET', 'POST'], 'default' => 'POST'],
                ['name' => 'to_param', 'label' => 'Name of the phone field', 'type' => 'text', 'default' => 'to', 'required' => true],
                ['name' => 'message_param', 'label' => 'Name of the message field', 'type' => 'text', 'default' => 'message', 'required' => true],
                ['name' => 'extra', 'label' => 'Other fields they ask for (key=value&key=value)', 'type' => 'text', 'secret' => true, 'help' => 'Your API key and sender name go here. They are stored encrypted.'],
                ['name' => 'success_text', 'label' => 'A word their answer must contain when it worked (optional)', 'type' => 'text'],
            ],
        ],

        'social:google' => [
            'name' => 'Google', 'ready' => true, 'help' => 'Create an OAuth client in Google Cloud Console. The redirect address to register is shown after you save.',
            'fields' => [['name' => 'client_id', 'label' => 'Client ID', 'type' => 'text', 'required' => true], ['name' => 'client_secret', 'label' => 'Client secret', 'type' => 'text', 'secret' => true, 'required' => true]],
        ],
        'social:facebook' => [
            'name' => 'Facebook', 'ready' => true, 'help' => 'Create an app in Meta for Developers and turn on Facebook Login.',
            'fields' => [['name' => 'app_id', 'label' => 'App ID', 'type' => 'text', 'required' => true], ['name' => 'app_secret', 'label' => 'App secret', 'type' => 'text', 'secret' => true, 'required' => true]],
        ],

        'payment:bkash' => [
            'name' => 'bKash (payment gateway)', 'ready' => false, 'help' => 'From your bKash merchant account (tokenized checkout).',
            'fields' => [
                ['name' => 'app_key', 'label' => 'App key', 'type' => 'text', 'required' => true], ['name' => 'app_secret', 'label' => 'App secret', 'type' => 'text', 'secret' => true, 'required' => true],
                ['name' => 'username', 'label' => 'Username', 'type' => 'text', 'required' => true], ['name' => 'password', 'label' => 'Password', 'type' => 'text', 'secret' => true, 'required' => true],
            ],
        ],
        'payment:nagad' => [
            'name' => 'Nagad (payment gateway)', 'ready' => false, 'help' => 'From your Nagad merchant account.',
            'fields' => [
                ['name' => 'merchant_id', 'label' => 'Merchant ID', 'type' => 'text', 'required' => true], ['name' => 'merchant_private_key', 'label' => 'Merchant private key', 'type' => 'textarea', 'secret' => true, 'required' => true],
                ['name' => 'gateway_public_key', 'label' => 'Nagad public key', 'type' => 'textarea', 'secret' => true, 'required' => true],
            ],
        ],
        'payment:sslcommerz' => [
            'name' => 'SSLCommerz (cards, bank and mobile money)', 'ready' => true, 'help' => 'One account for cards, internet banking, bKash, Nagad and Rocket. Testing uses their practice gateway (no real money, and orders are not marked paid); only Live takes real money. In their panel, give them the address shown below as the message (IPN) address.',
            'fields' => [['name' => 'store_id', 'label' => 'Store ID', 'type' => 'text', 'required' => true], ['name' => 'store_password', 'label' => 'Store password', 'type' => 'text', 'secret' => true, 'required' => true]],
        ],

        'courier:steadfast' => [
            'name' => 'Steadfast', 'ready' => true, 'help' => 'From your Steadfast merchant panel, under API. Parcels are booked only when this is switched on and set to Live.',
            'fields' => [
                ['name' => 'api_key', 'label' => 'API key', 'type' => 'text', 'secret' => true, 'required' => true],
                ['name' => 'secret_key', 'label' => 'Secret key', 'type' => 'text', 'secret' => true, 'required' => true],
                ['name' => 'webhook_token', 'label' => 'Password for their progress messages (optional)', 'type' => 'text', 'secret' => true, 'help' => 'Make up a long password. Give Steadfast this same password and the address shown below, and parcel progress arrives by itself.'],
                ['name' => 'base_url', 'label' => 'Address of the service', 'type' => 'url', 'default' => 'https://portal.steadfast.com.bd/api/v1'],
            ],
        ],
        'courier:pathao' => [
            'name' => 'Pathao', 'ready' => true, 'help' => 'From your Pathao merchant panel, under the developer API. Parcels are booked only when this is switched on and set to Live. For Pathao\'s practice server, use their sandbox address below and keep it on Live. Progress is asked for every thirty minutes.',
            'fields' => [
                ['name' => 'client_id', 'label' => 'Client ID', 'type' => 'text', 'required' => true], ['name' => 'client_secret', 'label' => 'Client secret', 'type' => 'text', 'secret' => true, 'required' => true],
                ['name' => 'username', 'label' => 'Email', 'type' => 'text', 'required' => true], ['name' => 'password', 'label' => 'Password', 'type' => 'text', 'secret' => true, 'required' => true],
                ['name' => 'store_id', 'label' => 'Store ID', 'type' => 'text', 'required' => true],
                ['name' => 'base_url', 'label' => 'Address of the service', 'type' => 'url', 'default' => 'https://api-hermes.pathao.com'],
            ],
        ],

        'map:nominatim' => [
            'name' => 'OpenStreetMap (free)', 'ready' => true, 'help' => 'No key needed. Their rules ask for a contact email in each request, and allow about one lookup a second, so it suits a small shop; a busy shop should use Google Maps or its own server. The buyer\'s "Use my location" button fills in the address when this is on.',
            'fields' => [['name' => 'contact_email', 'label' => 'Your contact email', 'type' => 'text', 'required' => true], ['name' => 'base_url', 'label' => 'Address of the service', 'type' => 'url', 'default' => 'https://nominatim.openstreetmap.org']],
        ],
        'map:google' => [
            'name' => 'Google Maps', 'ready' => true, 'help' => 'Needs a billing-enabled Google Cloud project with the Geocoding API on. Keep the key limited to that API.',
            'fields' => [['name' => 'api_key', 'label' => 'API key', 'type' => 'text', 'secret' => true, 'required' => true]],
        ],
    ],
];
