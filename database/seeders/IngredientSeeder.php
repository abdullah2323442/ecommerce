<?php

namespace Database\Seeders;

use App\Models\Ingredient;
use Illuminate\Database\Seeder;

/**
 * A first version of the central ingredient list (spec ch 17). The owner keeps it from here: adds what restaurants ask for, switches off what
 * should go. Safe to run again; it only adds what is missing.
 */
class IngredientSeeder extends Seeder
{
    public const LIST = [
        'meat' => ['Beef patty' => 'গরুর প্যাটি', 'Chicken patty' => 'মুরগির প্যাটি', 'Grilled chicken' => 'গ্রিল চিকেন', 'Fried chicken' => 'ফ্রাইড চিকেন', 'Mutton' => 'খাসি', 'Beef slices' => 'গরুর স্লাইস', 'Sausage' => 'সসেজ', 'Shami kabab' => 'শামি কাবাব', 'Tikka chicken' => 'টিক্কা চিকেন', 'Bacon' => 'বেকন', 'Pepperoni' => 'পেপারনি', 'Tuna' => 'টুনা'],
        'cheese' => ['Cheddar' => 'চেডার', 'Mozzarella' => 'মোজারেলা', 'Cream cheese' => 'ক্রিম চিজ', 'Parmesan' => 'পারমেসান', 'Processed cheese slice' => 'চিজ স্লাইস'],
        'vegetables' => ['Tomato' => 'টমেটো', 'Lettuce' => 'লেটুস', 'Onion' => 'পেঁয়াজ', 'Cucumber' => 'শসা', 'Capsicum' => 'ক্যাপসিকাম', 'Mushroom' => 'মাশরুম', 'Olives' => 'অলিভ', 'Sweet corn' => 'ভুট্টা', 'Cabbage' => 'বাঁধাকপি', 'Carrot' => 'গাজর', 'Green chilli' => 'কাঁচা মরিচ', 'Pickled cucumber' => 'আচারি শসা', 'Pineapple' => 'আনারস', 'Potato wedges' => 'আলুর ওয়েজ'],
        'sauces' => ['Mayonnaise' => 'মেয়োনেজ', 'Ketchup' => 'কেচাপ', 'Barbecue sauce' => 'বারবিকিউ সস', 'Mustard' => 'মাস্টার্ড', 'Chilli sauce' => 'চিলি সস', 'Garlic sauce' => 'রসুনের সস', 'Ranch' => 'র‍্যাঞ্চ', 'Sweet chilli sauce' => 'সুইট চিলি সস', 'Tomato sauce' => 'টমেটো সস', 'Pesto' => 'পেস্টো'],
        'extras' => ['Egg' => 'ডিম', 'Jalapeño' => 'জালাপেনো', 'Fries' => 'ফ্রাইস', 'Nuggets' => 'নাগেটস', 'Extra bun' => 'বাড়তি বান', 'Hash brown' => 'হ্যাশ ব্রাউন', 'Coleslaw' => 'কোলস্লো', 'Onion rings' => 'অনিয়ন রিং'],
        'spices' => ['Black pepper' => 'গোলমরিচ', 'Oregano' => 'অরিগানো', 'Chilli flakes' => 'চিলি ফ্লেক্স', 'Garlic' => 'রসুন', 'Peri peri' => 'পেরি পেরি', 'Cajun spice' => 'কাজুন মশলা', 'Chaat masala' => 'চাট মশলা'],
    ];

    public function run(): void
    {
        foreach (self::LIST as $group => $items) {
            foreach ($items as $name => $bn) {
                Ingredient::firstOrCreate(['name' => $name], ['name_bn' => $bn, 'group' => $group, 'is_active' => true]);
            }
        }
    }
}
