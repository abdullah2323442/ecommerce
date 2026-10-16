<?php

/**
 * The categories a trade buyer can be given (spec ch 14: each one sees only their own). Only medicine has a catalogue
 * today; the others are ready for the sector builder (ch 15) and are stored and enforced the same way.
 */
return [
    'categories' => [
        'medicine' => 'Medicine',
        'wood' => 'Wood',
        'clothing' => 'Clothing',
        'grocery' => 'Grocery',
        'electronics' => 'Electronics',
    ],
];
