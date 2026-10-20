<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Listing;
use App\Models\Sector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Category trees for a development shop. Every kind of shop gets a tree as deep as it needs (Electronics > Audio > Headphones > Over-ear), and every
 * sample product is put in the deepest node that fits it by words in its title. The trees and the matches are sample data: the owner builds the real
 * ones in Admin > Market. It refuses to run on a production server and is safe to run again: a node already there is updated, never doubled.
 */
class SeedDemoCategories extends Command
{
    protected $signature = 'demo:categories {--force : Allow it on a production server}';

    protected $description = 'Add category trees to every kind of shop and sort the sample products into them (development only)';

    /** sector => nested tree: [name, bn, [children...]] ; a leaf has no third item. */
    private const TREES = [
        'electronics' => [
            ['Phones and tablets', 'ফোন ও ট্যাবলেট', [
                ['Smartphones', 'স্মার্টফোন', [['Flagship', 'ফ্ল্যাগশিপ'], ['Everyday', 'প্রতিদিনের']]],
                ['Tablets', 'ট্যাবলেট'],
                ['Phone accessories', 'ফোনের অ্যাক্সেসরি', [['Cases and covers', 'কেস ও কভার'], ['Chargers and power banks', 'চার্জার ও পাওয়ার ব্যাংক']]],
            ]],
            ['Computers', 'কম্পিউটার', [
                ['Laptops', 'ল্যাপটপ', [['Ultrabooks', 'আলট্রাবুক'], ['Gaming laptops', 'গেমিং ল্যাপটপ']]],
                ['Monitors', 'মনিটর'],
                ['Keyboards and mice', 'কিবোর্ড ও মাউস'],
                ['Storage', 'স্টোরেজ'],
            ]],
            ['Audio', 'অডিও', [
                ['Headphones', 'হেডফোন', [['Over-ear', 'ওভার-ইয়ার'], ['Earbuds', 'ইয়ারবাড']]],
                ['Speakers', 'স্পিকার', [['Bluetooth speakers', 'ব্লুটুথ স্পিকার'], ['Soundbars', 'সাউন্ডবার']]],
            ]],
            ['Cameras', 'ক্যামেরা', [['Mirrorless', 'মিররলেস'], ['Action cameras', 'অ্যাকশন ক্যামেরা'], ['Lenses and tripods', 'লেন্স ও ট্রাইপড']]],
            ['Wearables', 'ওয়েয়ারেবল', [['Smartwatches', 'স্মার্টওয়াচ'], ['Fitness bands', 'ফিটনেস ব্যান্ড']]],
            ['Gaming', 'গেমিং', [['Controllers', 'কন্ট্রোলার'], ['VR headsets', 'ভিআর হেডসেট'], ['Consoles', 'কনসোল']]],
            ['Home tech', 'হোম টেক', [['Air purifiers', 'এয়ার পিউরিফায়ার'], ['Projectors', 'প্রজেক্টর'], ['Smart lighting', 'স্মার্ট লাইটিং']]],
        ],
        'clothing' => [
            ['Women', 'নারী', [['Sarees', 'শাড়ি'], ['Scarves and stoles', 'স্কার্ফ ও স্টোল'], ['Kurtas and tops', 'কুর্তা ও টপ']]],
            ['Men', 'পুরুষ', [['Panjabis', 'পাঞ্জাবি'], ['Shirts', 'শার্ট', [['Casual shirts', 'ক্যাজুয়াল শার্ট'], ['Formal shirts', 'ফরমাল শার্ট']]], ['Jeans and jackets', 'জিন্স ও জ্যাকেট']]],
            ['Footwear', 'জুতা', [['Sneakers', 'স্নিকার']]],
            ['Bags and accessories', 'ব্যাগ ও অ্যাক্সেসরি', [['Handbags', 'হ্যান্ডব্যাগ'], ['Totes', 'টোট']]],
        ],
        'wood' => [
            ['Living room', 'বসার ঘর', [['Chairs', 'চেয়ার'], ['Tables', 'টেবিল'], ['Shelves', 'তাক']]],
            ['Bedroom', 'শোবার ঘর', [['Beds', 'বিছানা'], ['Wardrobes', 'আলমারি'], ['Bedside tables', 'বেডসাইড টেবিল']]],
            ['Dining', 'খাবার ঘর', [['Dining sets', 'ডাইনিং সেট'], ['Trays and boards', 'ট্রে ও বোর্ড']]],
        ],
        'jewellery' => [
            ['Gold', 'সোনা', [['Necklace sets', 'নেকলেস সেট'], ['Bangles', 'চুড়ি'], ['Earrings', 'কানের দুল']]],
            ['Diamond', 'হীরা', [['Rings', 'আংটি']]],
            ['Silver and fashion', 'রুপা ও ফ্যাশন', [['Bracelets', 'ব্রেসলেট'], ['Pendants', 'পেন্ডেন্ট'], ['Pearls', 'মুক্তা']]],
        ],
        'beauty' => [
            ['Skincare', 'ত্বকের যত্ন', [['Serums', 'সিরাম'], ['Moisturisers', 'ময়েশ্চারাইজার'], ['Body care', 'শরীরের যত্ন']]],
            ['Fragrance', 'সুগন্ধি', [['Perfumes', 'পারফিউম']]],
            ['Makeup', 'মেকআপ', [['Lips', 'ঠোঁট']]],
            ['Hair and bath', 'চুল ও গোসল', [['Hair oil', 'চুলের তেল'], ['Soaps', 'সাবান']]],
        ],
        'home' => [
            ['Decor', 'সাজসজ্জা', [['Vases', 'ফুলদানি'], ['Lamps', 'ল্যাম্প'], ['Throws', 'থ্রো']]],
            ['Kitchen and dining', 'রান্নাঘর ও খাওয়া', [['Dinner sets', 'ডিনার সেট'], ['Tea sets', 'চায়ের সেট']]],
        ],
        'books' => [
            ['Books', 'বই', [['Poetry', 'কবিতা']]],
            ['Stationery', 'স্টেশনারি', [['Notebooks and journals', 'নোটবুক ও জার্নাল'], ['Pens', 'কলম']]],
        ],
        'grocery' => [
            ['Honey and sweeteners', 'মধু ও মিষ্টি', [['Honey', 'মধু']]],
            ['Oils', 'তেল', [['Mustard oil', 'সরিষার তেল']]],
            ['Tea and nuts', 'চা ও বাদাম', [['Tea', 'চা'], ['Nuts', 'বাদাম']]],
        ],
    ];

    /** Words in a title => the leaf node's name. The first match wins, so the specific words come first. */
    private const MATCH = [
        'electronics' => ['vr ' => 'VR headsets', 'action camera' => 'Action cameras', 'over-ear' => 'Over-ear', 'earbuds' => 'Earbuds', 'power bank' => 'Chargers and power banks', 'gaming laptop' => 'Gaming laptops', 'laptop' => 'Ultrabooks', 'titanium smartphone' => 'Flagship', 'smartphone' => 'Everyday', 'tablet' => 'Tablets', 'camera' => 'Mirrorless', 'smartwatch' => 'Smartwatches', 'smart watch' => 'Smartwatches', 'speaker' => 'Bluetooth speakers', 'controller' => 'Controllers', 'vr ' => 'VR headsets', 'projector' => 'Projectors', 'purifier' => 'Air purifiers', 'monitor' => 'Monitors', 'mouse' => 'Keyboards and mice', 'keyboard' => 'Keyboards and mice', 'ssd' => 'Storage', 'soundbar' => 'Soundbars', 'band' => 'Fitness bands', 'case' => 'Cases and covers', 'bulb' => 'Smart lighting', 'headphones' => 'Over-ear'],
        'clothing' => ['saree' => 'Sarees', 'scarf' => 'Scarves and stoles', 'panjabi' => 'Panjabis', 'kurta' => 'Kurtas and tops', 'oxford' => 'Casual shirts', 'shirt' => 'Casual shirts', 'cotton shirt' => 'Casual shirts', 'jeans' => 'Jeans and jackets', 'jacket' => 'Jeans and jackets', 'sneakers' => 'Sneakers', 'handbag' => 'Handbags', 'tote' => 'Totes'],
        'wood' => ['dining table' => 'Dining sets', 'chair' => 'Chairs', 'rocking' => 'Chairs', 'tray' => 'Trays and boards', 'bookshelf' => 'Shelves', 'bedside' => 'Bedside tables', 'wardrobe' => 'Wardrobes', 'bed ' => 'Beds'],
        'jewellery' => ['necklace set' => 'Necklace sets', 'pearl' => 'Pearls', 'bangle' => 'Bangles', 'earrings' => 'Earrings', 'jhumka' => 'Earrings', 'ring' => 'Rings', 'cuff' => 'Bracelets', 'pendant' => 'Pendants'],
        'beauty' => ['serum' => 'Serums', 'butter' => 'Body care', 'cream' => 'Moisturisers', 'perfume' => 'Perfumes', 'parfum' => 'Perfumes', 'lipstick' => 'Lips', 'hair oil' => 'Hair oil', 'soap' => 'Soaps'],
        'home' => ['vase' => 'Vases', 'lamp' => 'Lamps', 'blanket' => 'Throws', 'dinner' => 'Dinner sets', 'tea set' => 'Tea sets'],
        'books' => ['poetry' => 'Poetry', 'notebook' => 'Notebooks and journals', 'journal' => 'Notebooks and journals', 'pen' => 'Pens'],
        'grocery' => ['honey' => 'Honey', 'mustard' => 'Mustard oil', 'tea' => 'Tea', 'nuts' => 'Nuts'],
    ];

    public function handle(): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('Refusing to add sample categories on a production server. Use --force if you really mean it.');

            return self::FAILURE;
        }

        $nodes = 0;
        $placed = 0;

        foreach (self::TREES as $slug => $tree) {
            $sector = Sector::where('slug', $slug)->first();

            if (! $sector) {
                continue;
            }

            $nodes += $this->grow($sector, null, $tree);
            $leaves = Category::where('sector_id', $sector->id)->get()->keyBy('name');

            foreach (Listing::where('sector_id', $sector->id)->get() as $listing) {
                $title = mb_strtolower($listing->title.' ');

                foreach (self::MATCH[$slug] as $word => $leaf) {
                    if (str_contains($title, $word) && isset($leaves[$leaf])) {
                        $listing->update(['category_id' => $leaves[$leaf]->id]);
                        $placed++;
                        break;
                    }
                }
            }
        }

        Cache::flush();
        $this->info("Added or updated {$nodes} categories and placed {$placed} products.");

        return self::SUCCESS;
    }

    /** @param array<int, array> $children */
    private function grow(Sector $sector, ?Category $parent, array $children): int
    {
        $count = 0;

        foreach ($children as $i => $row) {
            [$name, $bn] = $row;
            $slug = Str::slug($name);
            $node = Category::updateOrCreate(['sector_id' => $sector->id, 'slug' => $slug], ['parent_id' => $parent?->id, 'name' => $name, 'name_bn' => $bn, 'sort' => $i + 1, 'is_active' => true]);
            $count++;

            if (isset($row[2])) {
                $count += $this->grow($sector, $node, $row[2]);
            }
        }

        return $count;
    }
}
