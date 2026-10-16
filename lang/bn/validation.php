<?php

/**
 * Bangla validation messages. Any rule not listed here falls back to the English text from the framework.
 * `attributes` are the names of the fields as a person reads them, so "The name field is required" becomes
 * "নাম লিখতে হবে".
 */
return [
    'accepted' => ':attribute গ্রহণ করতে হবে।',
    'array' => ':attribute একটি তালিকা হতে হবে।',
    'between' => [
        'array' => ':attribute-এ :min থেকে :max টি জিনিস থাকতে হবে।',
        'file' => ':attribute :min থেকে :max কিলোবাইটের মধ্যে হতে হবে।',
        'numeric' => ':attribute :min থেকে :max-এর মধ্যে হতে হবে।',
        'string' => ':attribute :min থেকে :max অক্ষরের মধ্যে হতে হবে।',
    ],
    'boolean' => ':attribute হ্যাঁ বা না হতে হবে।',
    'confirmed' => ':attribute মিলছে না।',
    'date' => ':attribute একটি সঠিক তারিখ হতে হবে।',
    'date_format' => ':attribute-এর রূপ :format হতে হবে।',
    'digits' => ':attribute :digits অঙ্কের হতে হবে।',
    'distinct' => ':attribute-এ একই মান দুইবার আছে।',
    'email' => ':attribute একটি সঠিক ইমেইল ঠিকানা হতে হবে।',
    'exists' => 'বেছে নেওয়া :attribute সঠিক নয়।',
    'file' => ':attribute একটি ফাইল হতে হবে।',
    'filled' => ':attribute লিখতে হবে।',
    'gt' => [
        'array' => ':attribute-এ :value টির বেশি জিনিস থাকতে হবে।',
        'file' => ':attribute :value কিলোবাইটের বেশি হতে হবে।',
        'numeric' => ':attribute :value-এর বেশি হতে হবে।',
        'string' => ':attribute :value অক্ষরের বেশি হতে হবে।',
    ],
    'image' => ':attribute একটি ছবি হতে হবে।',
    'in' => 'বেছে নেওয়া :attribute সঠিক নয়।',
    'integer' => ':attribute একটি পূর্ণ সংখ্যা হতে হবে।',
    'max' => [
        'array' => ':attribute-এ :max টির বেশি জিনিস থাকতে পারবে না।',
        'file' => ':attribute :max কিলোবাইটের বেশি হতে পারবে না।',
        'numeric' => ':attribute :max-এর বেশি হতে পারবে না।',
        'string' => ':attribute :max অক্ষরের বেশি হতে পারবে না।',
    ],
    'mimes' => ':attribute :values ধরনের ফাইল হতে হবে।',
    'min' => [
        'array' => ':attribute-এ কমপক্ষে :min টি জিনিস থাকতে হবে।',
        'file' => ':attribute কমপক্ষে :min কিলোবাইট হতে হবে।',
        'numeric' => ':attribute কমপক্ষে :min হতে হবে।',
        'string' => ':attribute কমপক্ষে :min অক্ষরের হতে হবে।',
    ],
    'numeric' => ':attribute একটি সংখ্যা হতে হবে।',
    'present' => ':attribute থাকতে হবে।',
    'regex' => ':attribute-এর রূপ সঠিক নয়।',
    'required' => ':attribute লিখতে হবে।',
    'required_with' => ':values থাকলে :attribute লিখতে হবে।',
    'required_without' => ':values না থাকলে :attribute লিখতে হবে।',
    'same' => ':attribute আর :other মিলতে হবে।',
    'size' => [
        'file' => ':attribute :size কিলোবাইট হতে হবে।',
        'numeric' => ':attribute :size হতে হবে।',
        'string' => ':attribute :size অক্ষরের হতে হবে।',
    ],
    'string' => ':attribute লেখা হতে হবে।',
    'unique' => ':attribute আগেই নেওয়া হয়ে গেছে।',
    'uploaded' => ':attribute আপলোড করা যায়নি।',
    'url' => ':attribute একটি সঠিক লিংক হতে হবে।',
    'custom' => [],
    'attributes' => [
        'name' => 'নাম', 'email' => 'ইমেইল', 'phone' => 'মোবাইল নম্বর', 'password' => 'পাসওয়ার্ড', 'current_password' => 'বর্তমান পাসওয়ার্ড',
        'address' => 'ঠিকানা', 'area' => 'এলাকা', 'district' => 'জেলা', 'how_to_find' => 'কীভাবে পৌঁছাতে হবে', 'for_whom' => 'যার জন্য',
        'delivery_mode' => 'পাওয়ার উপায়', 'note' => 'নোট', 'quantity' => 'পরিমাণ', 'unit' => 'একক', 'reason' => 'কারণ', 'reason_code' => 'কারণ',
        'business_name' => 'ব্যবসার নাম', 'business_kind' => 'ব্যবসার ধরন', 'categories' => 'ক্যাটাগরি', 'license' => 'লাইসেন্স', 'second_license' => 'দ্বিতীয় লাইসেন্স',
        'license_number' => 'লাইসেন্স নম্বর', 'buyer_name' => 'ক্রেতার নাম', 'buyer_phone' => 'ক্রেতার ফোন', 'buyer_address' => 'ক্রেতার ঠিকানা',
        'prescription_no' => 'প্রেসক্রিপশন নম্বর', 'doctor_name' => 'ডাক্তারের নাম', 'medicine_id' => 'ওষুধ', 'payment_terms' => 'টাকা দেওয়ার উপায়',
        'payment_method' => 'টাকা দেওয়ার উপায়', 'paid_amount' => 'দেওয়া টাকা', 'percent' => 'শতাংশ', 'discount_percent' => 'ছাড়ের শতাংশ', 'fixed_price' => 'নির্দিষ্ট দাম',
        'tier_id' => 'টিয়ার', 'role_id' => 'ভূমিকা', 'permissions' => 'অনুমতি', 'days' => 'দিন', 'charge' => 'খরচ', 'width' => 'প্রস্থ', 'file' => 'ফাইল',
        'voice' => 'ভয়েস নোট', 'items' => 'পণ্য', 'moq' => 'ন্যূনতম পরিমাণ', 'account_type' => 'অ্যাকাউন্টের ধরন', 'from' => 'শুরুর তারিখ', 'to' => 'শেষ তারিখ',
    ],
];
