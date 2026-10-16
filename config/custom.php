<?php

/*
 * Made-to-order work: wood and clothing (spec ch 16). The measurement fields of each kind, in the order they are asked.
 * Labels are English; the screens translate them.
 */
return [
    'kinds' => ['wood', 'clothing'],

    'units' => ['inch', 'foot', 'cm'],

    // Garment measurements, one list for a man and one for a woman.
    'fields' => [
        'wood' => ['length' => 'Length', 'width' => 'Width', 'height' => 'Height', 'depth' => 'Depth or thickness'],
        'men' => ['chest' => 'Chest', 'waist' => 'Waist', 'length' => 'Length', 'sleeve' => 'Sleeve', 'shoulder' => 'Shoulder', 'neck' => 'Neck', 'trouser_length' => 'Trouser length', 'thigh' => 'Thigh', 'hem' => 'Hem (mohori)'],
        'women' => ['bust' => 'Bust', 'waist' => 'Waist', 'hip' => 'Hip', 'length' => 'Length', 'sleeve' => 'Sleeve', 'shoulder' => 'Shoulder', 'neck_depth' => 'Neck depth', 'kameez_length' => 'Kameez length', 'salwar_length' => 'Salwar length'],
    ],

    // Extra choices on a wood order and on a clothing order.
    'wood_options' => ['polish' => 'Polish or colour', 'drawers' => 'Drawers or shelves', 'handles' => 'Handles', 'glass' => 'Glass'],
    'clothing_options' => ['colour' => 'Colour', 'collar' => 'Collar style', 'sleeve_style' => 'Sleeve style', 'buttons' => 'Buttons', 'pockets' => 'Pockets'],
];
