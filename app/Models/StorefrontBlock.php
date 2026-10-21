<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One section of a shop's front page. The admin adds, orders, hides and words these; the page draws whatever is here, in this order.
 * The words are kept in English and in Bengali; a section with no Bengali line shows the English one.
 */
class StorefrontBlock extends Model
{
    /** kind => its name and what the admin may set on it. */
    public const KINDS = [
        'hero' => ['label' => 'Opening banner', 'fields' => ['title', 'text', 'button', 'image']],
        'categories' => ['label' => 'Category tiles', 'fields' => ['title', 'limit']],
        'products' => ['label' => 'Product shelf', 'fields' => ['title', 'text', 'source', 'limit']],
        'banner' => ['label' => 'Featured product banner', 'fields' => ['title', 'button', 'source', 'image']],
        'budget' => ['label' => 'Shop by budget', 'fields' => ['title', 'text']],
        'premium' => ['label' => 'Premium picks', 'fields' => ['title', 'source', 'limit']],
        'service' => ['label' => 'Service strip', 'fields' => ['items']],
        'faq' => ['label' => 'Questions and answers', 'fields' => ['title', 'items']],
        'cta' => ['label' => 'Closing button', 'fields' => ['button']],
        'steps' => ['label' => 'Steps', 'fields' => ['title', 'items']],
        'forms' => ['label' => 'Medicine forms', 'fields' => ['title', 'text', 'limit']],
        'companies' => ['label' => 'Pharmaceutical companies', 'fields' => ['title', 'limit']],
        'fresh' => ['label' => 'Newly added medicines', 'fields' => ['title', 'limit']],
        'browse' => ['label' => 'All medicines, A to Z', 'fields' => ['title']],
        'cuisines' => ['label' => 'Food categories', 'fields' => ['title', 'limit']],
        'dishes' => ['label' => 'Dish shelf', 'fields' => ['title', 'text', 'source', 'limit']],
        'restaurants' => ['label' => 'Restaurants', 'fields' => ['title', 'limit']],
        'games' => ['label' => 'Games', 'fields' => ['title', 'limit']],
        'packs' => ['label' => 'Top-up packs', 'fields' => ['title', 'text', 'source', 'limit']],
        'departments' => ['label' => 'Shops as tiles', 'fields' => ['title', 'text', 'limit']],
        'promos' => ['label' => 'Promo banners', 'fields' => ['items', 'media']],
        'collections' => ['label' => 'Collections', 'fields' => ['title', 'text', 'items', 'media']],
        'sellers' => ['label' => 'Trusted sellers', 'fields' => ['title', 'text', 'limit']],
        'reviews' => ['label' => 'Buyer words', 'fields' => ['title', 'limit']],
        'showcase' => ['label' => 'Colour collage banner', 'fields' => ['title', 'text', 'button', 'link', 'items', 'media', 'tone']],
        'ticker' => ['label' => 'Moving line of promises', 'fields' => ['items']],
        'numbers' => ['label' => 'Numbers band', 'fields' => ['title', 'items']],
        'feature' => ['label' => 'Picture and story', 'fields' => ['title', 'text', 'button', 'link', 'image', 'items']],
        'rail' => ['label' => 'Round-picture rail', 'fields' => ['title', 'text', 'items', 'media']],
    ];

    /** The stand-alone pages, and the kinds each may use. A shop (sector) uses SHOP_KINDS. */
    public const PAGES = [
        'medicines' => ['label' => 'Pharmacy', 'kinds' => ['hero', 'service', 'forms', 'fresh', 'companies', 'browse', 'faq']],
        'food' => ['label' => 'Food', 'kinds' => ['hero', 'cuisines', 'dishes', 'banner', 'steps', 'restaurants', 'service', 'faq']],
        'gaming' => ['label' => 'Game top-up', 'kinds' => ['hero', 'games', 'packs', 'steps', 'service', 'faq']],
        'market' => ['label' => 'Marketplace', 'kinds' => ['hero', 'showcase', 'ticker', 'departments', 'products', 'promos', 'collections', 'feature', 'rail', 'numbers', 'premium', 'banner', 'budget', 'sellers', 'reviews', 'steps', 'service', 'faq', 'cta']],
    ];

    public const SHOP_KINDS = ['hero', 'categories', 'products', 'banner', 'budget', 'premium', 'service', 'faq', 'cta', 'steps'];

    /** The kinds the admin may add to a shop (page is null) or to one of the pages. */
    public static function kindsFor(?string $page): array
    {
        $keys = $page === null ? self::SHOP_KINDS : (self::PAGES[$page]['kinds'] ?? []);

        return collect($keys)->mapWithKeys(fn ($k) => [$k => self::KINDS[$k]])->all();
    }

    public const MAX_PER_SHOP = 40;

    /** The colours an item may wear; the page draws each from its own theme. */
    public const TONES = ['saffron', 'rose', 'jade', 'indigo', 'clay', 'plum'];

    protected $fillable = ['sector_id', 'page', 'kind', 'sort', 'is_active', 'title', 'title_bn', 'text', 'text_bn', 'button', 'button_bn', 'link', 'source', 'item_limit', 'image_path', 'items'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'items' => 'array', 'item_limit' => 'integer', 'sort' => 'integer'];
    }

    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    /** The line in the viewer's language, falling back to English. */
    public function say(string $field): ?string
    {
        $bn = app()->getLocale() === 'bn' ? $this->{$field.'_bn'} : null;

        return $bn ?: ($this->{$field} ?: null);
    }

    /** The list items in the viewer's language, each with its link and picture when it has them. */
    public function itemList(): array
    {
        $bn = app()->getLocale() === 'bn';

        return collect($this->items ?? [])->map(fn ($i, $n) => [
            'title' => $bn && ! empty($i['title_bn']) ? $i['title_bn'] : ($i['title'] ?? ''),
            'text' => $bn && ! empty($i['text_bn']) ? $i['text_bn'] : ($i['text'] ?? ''),
            'link' => $i['link'] ?? null,
            'tone' => $i['tone'] ?? null,
            'image' => ! empty($i['image_path']) ? route('market.storefront-item-image', ['block' => $this->id, 'index' => $n, 'v' => substr(md5($i['image_path']), 0, 8)]) : null,
        ])->filter(fn ($i) => $i['title'] !== '')->values()->all();
    }

    /** The standard front page, for a shop that has none yet. Safe to call again: it only fills an empty shop. */
    public static function seedDefaults(Sector $sector): void
    {
        if (self::where('sector_id', $sector->id)->exists()) {
            return;
        }

        $service = [
            ['title' => 'Delivery across Bangladesh', 'title_bn' => 'সারা বাংলাদেশে ডেলিভারি', 'text' => 'Dhaka in a day or two, the rest of the country in three to five.', 'text_bn' => 'ঢাকায় এক থেকে দুই দিনে, দেশের বাকি অংশে তিন থেকে পাঁচ দিনে।'],
            ['title' => 'Original products only', 'title_bn' => 'শুধু আসল পণ্য', 'text' => 'Every product shows its warranty before you order.', 'text_bn' => 'অর্ডারের আগেই প্রতিটি পণ্যে ওয়ারেন্টি দেখানো থাকে।'],
            ['title' => 'Easy returns', 'title_bn' => 'সহজ ফেরত', 'text' => 'Seven days to return an item that is not as described.', 'text_bn' => 'বর্ণনার সঙ্গে না মিললে সাত দিনের মধ্যে ফেরত দিন।'],
            ['title' => 'Help when you need it', 'title_bn' => 'দরকারে সাহায্য', 'text' => 'Ask a question on any product page and we answer it there.', 'text_bn' => 'যেকোনো পণ্যের পাতায় প্রশ্ন করুন, সেখানেই উত্তর পাবেন।'],
        ];
        $faq = [
            ['title' => 'Are these products original?', 'title_bn' => 'এই পণ্যগুলো কি আসল?', 'text' => 'Yes. Sellers list original products and the warranty is shown on each page. If something is not as described, return it within seven days.', 'text_bn' => 'হ্যাঁ। বিক্রেতারা আসল পণ্য তোলেন এবং প্রতিটি পাতায় ওয়ারেন্টি দেখানো থাকে। বর্ণনার সঙ্গে না মিললে সাত দিনের মধ্যে ফেরত দিন।'],
            ['title' => 'How long does delivery take?', 'title_bn' => 'ডেলিভারিতে কত সময় লাগে?', 'text' => 'Inside Dhaka it usually takes one to two days. Other districts take three to five. You see the delivery charge before you pay.', 'text_bn' => 'ঢাকার ভেতরে সাধারণত এক থেকে দুই দিন লাগে। অন্য জেলায় তিন থেকে পাঁচ দিন। পরিশোধের আগেই ডেলিভারি চার্জ দেখতে পাবেন।'],
            ['title' => 'Can I pay on delivery?', 'title_bn' => 'ডেলিভারির সময় কি টাকা দেওয়া যায়?', 'text' => 'Products marked cash on delivery can be paid for when they arrive. Others are paid at checkout.', 'text_bn' => 'ক্যাশ অন ডেলিভারি চিহ্নিত পণ্যের টাকা পণ্য হাতে পেয়ে দিতে পারবেন। বাকিগুলো চেকআউটে দিতে হবে।'],
            ['title' => 'What if the item has a fault?', 'title_bn' => 'পণ্যে সমস্যা থাকলে কী হবে?', 'text' => 'Use the warranty shown on the product page. Keep the receipt and contact the seller or write to us from your orders.', 'text_bn' => 'পণ্যের পাতায় দেখানো ওয়ারেন্টি ব্যবহার করুন। রসিদ রাখুন এবং বিক্রেতার সঙ্গে যোগাযোগ করুন বা আপনার অর্ডার থেকে আমাদের লিখুন।'],
        ];

        $rows = [
            ['hero', ['button' => 'Explore products', 'button_bn' => 'পণ্য দেখুন']],
            ['categories', ['title' => 'Most popular categories', 'title_bn' => 'সবচেয়ে জনপ্রিয় ক্যাটাগরি', 'item_limit' => 8]],
            ['products', ['title' => 'Best sellers', 'title_bn' => 'সবচেয়ে বেশি বিক্রি', 'source' => 'best', 'item_limit' => 8]],
            ['banner', ['title' => 'The finest piece in the shop', 'title_bn' => 'দোকানের সেরা পণ্য', 'button' => 'See this piece', 'button_bn' => 'এই পণ্যটি দেখুন', 'source' => 'top']],
            ['products', ['title' => 'New arrivals', 'title_bn' => 'নতুন এসেছে', 'source' => 'fresh', 'item_limit' => 8]],
            ['budget', ['title' => 'Shop by budget', 'title_bn' => 'বাজেট অনুযায়ী কিনুন', 'text' => 'Start from what you want to spend and see what fits.', 'text_bn' => 'কত খরচ করবেন তা দিয়ে শুরু করুন, কী মানায় দেখুন।']],
            ['premium', ['title' => 'Premium picks', 'title_bn' => 'প্রিমিয়াম বাছাই', 'source' => 'premium', 'item_limit' => 4]],
            ['service', ['items' => $service]],
            ['faq', ['title' => 'Questions before you buy', 'title_bn' => 'কেনার আগে প্রশ্ন', 'items' => $faq]],
            ['cta', ['button' => 'Shop all {n} products', 'button_bn' => 'সব {n}টি পণ্য দেখুন']],
        ];

        foreach ($rows as $i => [$kind, $fields]) {
            self::create(['sector_id' => $sector->id, 'kind' => $kind, 'sort' => ($i + 1) * 10] + $fields);
        }
    }

    /** The standard front page of a stand-alone page (pharmacy, food, game top-up). Only fills an empty page. */
    public static function seedPage(string $page): void
    {
        if (! isset(self::PAGES[$page]) || self::where('page', $page)->exists()) {
            return;
        }

        $item = fn (string $t, string $tb, string $x, string $xb) => ['title' => $t, 'title_bn' => $tb, 'text' => $x, 'text_bn' => $xb];

        $rows = match ($page) {
            'medicines' => [
                ['hero', ['title' => 'Find a medicine', 'title_bn' => 'ওষুধ খুঁজুন', 'text' => 'Search by brand or generic name, in English or Bangla. Small spelling mistakes are fine.', 'text_bn' => 'ব্র্যান্ড বা জেনেরিক নামে খুঁজুন, ইংরেজি বা বাংলায়। বানানে ছোটখাটো ভুল হলেও চলবে।', 'button' => 'Search', 'button_bn' => 'খুঁজুন']],
                ['service', ['items' => [
                    $item('Printed prices', 'ছাপানো দাম', 'Every price shown is the printed MRP of the pack.', 'যে দাম দেখানো হয় তা প্যাকের ছাপানো এমআরপি।'),
                    $item('Brand and generic', 'ব্র্যান্ড ও জেনেরিক', 'Search by brand name, or by the generic name to compare.', 'ব্র্যান্ড নামে খুঁজুন, বা তুলনা করতে জেনেরিক নামে।'),
                    $item('Prescription marked', 'প্রেসক্রিপশন চিহ্নিত', 'Medicines that need a prescription are marked on their page.', 'প্রেসক্রিপশন লাগে এমন ওষুধ তার পাতায় চিহ্নিত থাকে।'),
                    $item('Cold chain and controlled', 'কোল্ড চেইন ও নিয়ন্ত্রিত', 'Cold-chain and controlled medicines are flagged clearly.', 'কোল্ড চেইন ও নিয়ন্ত্রিত ওষুধ স্পষ্টভাবে চিহ্নিত থাকে।'),
                ]]],
                ['forms', ['title' => 'Browse by form', 'title_bn' => 'ধরন অনুযায়ী দেখুন', 'text' => 'Tablets, syrups, injections and more.', 'text_bn' => 'ট্যাবলেট, সিরাপ, ইনজেকশন আরও অনেক কিছু।', 'item_limit' => 8]],
                ['fresh', ['title' => 'Newly added brands', 'title_bn' => 'নতুন যোগ হওয়া ব্র্যান্ড', 'item_limit' => 8]],
                ['companies', ['title' => 'Pharmaceutical companies', 'title_bn' => 'ওষুধ কোম্পানি', 'item_limit' => 12]],
                ['browse', ['title' => 'All medicines, A to Z', 'title_bn' => 'সব ওষুধ, এ থেকে জেড']],
                ['faq', ['title' => 'Questions about medicines', 'title_bn' => 'ওষুধ নিয়ে প্রশ্ন', 'items' => [
                    $item('Is the price I see the price I pay?', 'যে দাম দেখছি সেটাই কি দিতে হবে?', 'The price shown is the printed MRP for the pack. A seller may show their own offer on the medicine page.', 'দেখানো দাম প্যাকের ছাপানো এমআরপি। বিক্রেতা ওষুধের পাতায় নিজের অফার দেখাতে পারেন।'),
                    $item('What if I cannot find my medicine?', 'আমার ওষুধ না পেলে কী করব?', 'Use the request button on the no-results page and we will look for it.', 'ফল না পেলে সেখানকার অনুরোধ বোতাম ব্যবহার করুন, আমরা খুঁজে দেখব।'),
                    $item('Do I need a prescription?', 'প্রেসক্রিপশন লাগবে কি?', 'Medicines that need one are marked. Controlled medicines are given only against a valid prescription.', 'যে ওষুধে লাগে তা চিহ্নিত থাকে। নিয়ন্ত্রিত ওষুধ শুধু বৈধ প্রেসক্রিপশনে দেওয়া হয়।'),
                ]]],
            ],
            'food' => [
                ['hero', ['title' => 'Hungry? Let us bring it.', 'title_bn' => 'খিদে পেয়েছে? আমরা পৌঁছে দেব।', 'text' => 'Fast food and biryani from restaurants near you. Choose how you want your meal.', 'text_bn' => 'আপনার কাছের রেস্তোরাঁ থেকে ফাস্ট ফুড আর বিরিয়ানি। কীভাবে খাবেন বেছে নিন।']],
                ['cuisines', ['title' => 'What are you craving?', 'title_bn' => 'কী খেতে ইচ্ছে করছে?', 'item_limit' => 8]],
                ['dishes', ['title' => 'Most ordered', 'title_bn' => 'সবচেয়ে বেশি অর্ডার', 'source' => 'best', 'item_limit' => 6]],
                ['banner', ['title' => 'Fresh from the kitchen', 'title_bn' => 'রান্নাঘর থেকে সদ্য', 'button' => 'Order now', 'button_bn' => 'এখনই অর্ডার করুন', 'source' => 'fresh']],
                ['steps', ['title' => 'How it works', 'title_bn' => 'কীভাবে কাজ করে', 'items' => [
                    $item('Choose how you want it', 'কীভাবে চান বেছে নিন', 'Delivery to your door, or eat at the restaurant.', 'দরজায় ডেলিভারি, অথবা রেস্তোরাঁয় বসে খাওয়া।'),
                    $item('Pick your dishes', 'খাবার বেছে নিন', 'Browse a menu, or build your own where the restaurant allows it.', 'মেনু দেখুন, অথবা যেখানে সুযোগ আছে সেখানে নিজের মতো বানান।'),
                    $item('Place the order', 'অর্ডার করুন', 'Give your name and phone number. You follow the order from your orders page.', 'নাম আর ফোন নম্বর দিন। অর্ডার পাতা থেকে অর্ডারের খবর দেখুন।'),
                ]]],
                ['restaurants', ['title' => 'Restaurants', 'title_bn' => 'রেস্তোরাঁ', 'item_limit' => 12]],
                ['service', ['items' => [
                    $item('No account needed', 'অ্যাকাউন্ট লাগে না', 'Order with just your name and phone number.', 'শুধু নাম আর ফোন নম্বর দিয়ে অর্ডার করুন।'),
                    $item('Charge shown first', 'চার্জ আগেই দেখা যায়', 'The delivery charge for your area is shown before you confirm.', 'নিশ্চিত করার আগেই আপনার এলাকার ডেলিভারি চার্জ দেখানো হয়।'),
                    $item('Open hours shown', 'খোলা থাকার সময় দেখানো', 'Every restaurant shows if it is open now.', 'প্রতিটি রেস্তোরাঁ এখন খোলা কি না তা দেখায়।'),
                    $item('Eat there too', 'রেস্তোরাঁয় বসেও খান', 'Choose Eat there to order ahead for a table.', 'আগে থেকে অর্ডার করে বসে খেতে "রেস্তোরাঁয় খাব" বেছে নিন।'),
                ]]],
                ['faq', ['title' => 'Questions about food orders', 'title_bn' => 'খাবার অর্ডার নিয়ে প্রশ্ন', 'items' => [
                    $item('Do I need an account?', 'অ্যাকাউন্ট লাগবে কি?', 'No. Give your name and phone number when you order.', 'না। অর্ডারের সময় নাম আর ফোন নম্বর দিন।'),
                    $item('Why does it ask for my area?', 'এলাকা জানতে চায় কেন?', 'Your area decides which restaurants can deliver to you and what the delivery charge is.', 'আপনার এলাকা ঠিক করে কোন রেস্তোরাঁ ডেলিভারি দিতে পারবে এবং চার্জ কত।'),
                    $item('Can I eat at the restaurant?', 'রেস্তোরাঁয় বসে খেতে পারি?', 'Yes, where the restaurant offers it. Choose Eat there at the top of the page.', 'হ্যাঁ, যেসব রেস্তোরাঁ এই সুবিধা দেয়। পাতার ওপরে "রেস্তোরাঁয় খাব" বেছে নিন।'),
                ]]],
            ],
            'gaming' => [
                ['hero', ['title' => 'Game top-up', 'title_bn' => 'গেম টপ-আপ', 'text' => 'Diamonds for your game. Pick the game, give your player ID, pick a package, pay online.', 'text_bn' => 'আপনার গেমের ডায়মন্ড। গেম বেছে নিন, প্লেয়ার আইডি দিন, প্যাকেজ বেছে অনলাইনে পরিশোধ করুন।']],
                ['games', ['title' => 'Choose your game', 'title_bn' => 'আপনার গেম বেছে নিন', 'item_limit' => 12]],
                ['steps', ['title' => 'Three steps', 'title_bn' => 'তিন ধাপে', 'items' => [
                    $item('Enter your player ID', 'প্লেয়ার আইডি দিন', 'Each game page says which ID it needs.', 'প্রতিটি গেমের পাতায় লেখা থাকে কোন আইডি লাগবে।'),
                    $item('Pick a pack', 'প্যাক বেছে নিন', 'Choose the diamonds or pass you want.', 'যে ডায়মন্ড বা পাস চান তা বেছে নিন।'),
                    $item('Pay with bKash, Nagad or Rocket', 'বিকাশ, নগদ বা রকেটে পরিশোধ', 'Top-ups are paid online.', 'টপ-আপের টাকা অনলাইনে দিতে হয়।'),
                ]]],
                ['packs', ['title' => 'Best value packs', 'title_bn' => 'সেরা দামের প্যাক', 'text' => 'The lowest priced packs across all games.', 'text_bn' => 'সব গেমের সবচেয়ে কম দামের প্যাক।', 'source' => 'value', 'item_limit' => 6]],
                ['service', ['items' => [
                    $item('Name checked first', 'আগে নাম মিলিয়ে দেখা', 'We show the player name for your ID before any money moves.', 'টাকা যাওয়ার আগেই আপনার আইডির প্লেয়ারের নাম দেখানো হয়।'),
                    $item('Paid online', 'অনলাইনে পরিশোধ', 'bKash, Nagad or Rocket. There is no cash on delivery.', 'বিকাশ, নগদ বা রকেট। ক্যাশ অন ডেলিভারি নেই।'),
                    $item('Every top-up tracked', 'প্রতিটি টপ-আপ ট্র্যাক হয়', 'See each top-up and its status in your orders.', 'আপনার অর্ডার পাতায় প্রতিটি টপ-আপ ও তার অবস্থা দেখুন।'),
                ]]],
                ['faq', ['title' => 'Questions about top-up', 'title_bn' => 'টপ-আপ নিয়ে প্রশ্ন', 'items' => [
                    $item('Where do I find my player ID?', 'প্লেয়ার আইডি কোথায় পাব?', 'In the game, on your profile screen. Each game page says which ID it needs.', 'গেমের ভেতরে আপনার প্রোফাইল স্ক্রিনে। প্রতিটি গেমের পাতায় কোন আইডি লাগবে লেখা থাকে।'),
                    $item('What if I type the wrong ID?', 'ভুল আইডি দিলে কী হবে?', 'We show the player name before you pay, so you can catch a wrong ID first.', 'পরিশোধের আগে প্লেয়ারের নাম দেখানো হয়, তাই ভুল আইডি আগেই ধরা পড়ে।'),
                    $item('Can I pay cash on delivery?', 'ক্যাশ অন ডেলিভারিতে দেওয়া যাবে?', 'No. Top-ups are paid online.', 'না। টপ-আপের টাকা অনলাইনে দিতে হয়।'),
                ]]],
            ],
            'market' => [
                ['showcase', ['title' => 'Everything beautiful, from across Bangladesh', 'title_bn' => 'সারা বাংলাদেশের সব সুন্দর জিনিস, এক জায়গায়', 'text' => 'Furniture, electronics, clothing, jewellery and care, from sellers you can trust. One honest price for everyone.', 'text_bn' => 'আসবাব, ইলেকট্রনিক্স, পোশাক, গয়না আর যত্নের জিনিস, ভরসার বিক্রেতাদের কাছ থেকে। সবার জন্য একই সৎ দাম।', 'button' => 'Explore products', 'button_bn' => 'পণ্য দেখুন', 'link' => '/market?all=1', 'items' => [
                    ['title' => 'Electronics', 'title_bn' => 'ইলেকট্রনিক্স', 'text' => 'Warranty shown', 'text_bn' => 'ওয়ারেন্টি দেখানো', 'link' => '/market?sector=electronics', 'tone' => 'indigo'],
                    ['title' => 'Wood', 'title_bn' => 'কাঠ', 'text' => 'Teak and sheesham', 'text_bn' => 'সেগুন আর শিশু', 'link' => '/market?sector=wood', 'tone' => 'clay'],
                    ['title' => 'Clothing', 'title_bn' => 'পোশাক', 'text' => 'Everyday cotton', 'text_bn' => 'রোজকার সুতি', 'link' => '/market?sector=clothing', 'tone' => 'rose'],
                    ['title' => 'Jewellery', 'title_bn' => 'গয়না', 'text' => 'Gold and silver', 'text_bn' => 'সোনা আর রুপা', 'link' => '/market?sector=jewellery', 'tone' => 'saffron'],
                    ['title' => 'Beauty', 'title_bn' => 'সৌন্দর্য', 'text' => 'Simple care', 'text_bn' => 'সহজ যত্ন', 'link' => '/market?sector=beauty', 'tone' => 'plum'],
                    ['title' => 'Home', 'title_bn' => 'ঘর', 'text' => 'Warm corners', 'text_bn' => 'উষ্ণ কোণ', 'link' => '/market?sector=home', 'tone' => 'jade'],
                ]]],
                ['ticker', ['items' => [
                    $item('One price for everyone', 'সবার জন্য এক দাম', '', ''),
                    $item('Warranty shown before you order', 'অর্ডারের আগেই ওয়ারেন্টি', '', ''),
                    $item('Delivery across Bangladesh', 'সারা বাংলাদেশে ডেলিভারি', '', ''),
                    $item('Cash on delivery where offered', 'যেখানে সুযোগ আছে সেখানে ক্যাশ অন ডেলিভারি', '', ''),
                    $item('Seven-day returns', 'সাত দিনে ফেরত', '', ''),
                ]]],
                ['departments', ['title' => 'Shop by department', 'title_bn' => 'বিভাগ অনুযায়ী কিনুন', 'text' => 'Every kind of shop, one click away.', 'text_bn' => 'সব ধরনের দোকান, এক ক্লিকেই।', 'item_limit' => 8]],
                ['products', ['title' => 'Just arrived', 'title_bn' => 'সদ্য এসেছে', 'source' => 'fresh', 'item_limit' => 8]],
                ['promos', ['items' => [
                    ['title' => 'The gift edit', 'title_bn' => 'উপহারের সংগ্রহ', 'text' => 'Gold, silk and handmade pieces for the people you love.', 'text_bn' => 'প্রিয়জনের জন্য সোনা, রেশম আর হাতে তৈরি জিনিস।', 'link' => '/market?sector=jewellery'],
                    ['title' => 'Tech, made simple', 'title_bn' => 'প্রযুক্তি, সহজ করে', 'text' => 'Phones, audio and laptops with the warranty shown.', 'text_bn' => 'ফোন, অডিও আর ল্যাপটপ, ওয়ারেন্টি দেখানো।', 'link' => '/market?sector=electronics'],
                ]]],
                ['collections', ['title' => 'Collections', 'title_bn' => 'সংগ্রহ', 'text' => 'Four ways into the bazaar.', 'text_bn' => 'বাজারে ঢোকার চারটি পথ।', 'items' => [
                    ['title' => 'Made of wood', 'title_bn' => 'কাঠে গড়া', 'text' => 'Teak and sheesham, built to last.', 'text_bn' => 'সেগুন আর শিশু, টেকসই করে গড়া।', 'link' => '/market?sector=wood'],
                    ['title' => 'Wear it well', 'title_bn' => 'ভালোভাবে পরুন', 'text' => 'Panjabis, shirts and everyday cotton.', 'text_bn' => 'পাঞ্জাবি, শার্ট আর রোজকার সুতি।', 'link' => '/market?sector=clothing'],
                    ['title' => 'Home, lit warm', 'title_bn' => 'ঘর, উষ্ণ আলোয়', 'text' => 'Lamps, vases and quiet corners.', 'text_bn' => 'বাতি, ফুলদানি আর শান্ত কোণ।', 'link' => '/market?sector=home'],
                    ['title' => 'Rituals of care', 'title_bn' => 'যত্নের রীতি', 'text' => 'Skin and body, kept simple.', 'text_bn' => 'ত্বক আর শরীর, সহজ যত্নে।', 'link' => '/market?sector=beauty'],
                ]]],
                ['products', ['title' => 'Most ordered', 'title_bn' => 'সবচেয়ে বেশি অর্ডার', 'source' => 'best', 'item_limit' => 8]],
                ['feature', ['title' => 'Made by hand, kept for years', 'title_bn' => 'হাতে গড়া, বছরের পর বছর রাখার মতো', 'text' => 'Teak that is seasoned, cloth that is woven, brass that is cast. Our makers put their name to every piece, and you see who made it before you order.', 'text_bn' => 'সেগুন পোক্ত করা, কাপড় বোনা, পিতল ঢালাই। কারিগররা প্রতিটি জিনিসে নিজের নাম রাখেন, অর্ডারের আগেই দেখতে পাবেন কে বানিয়েছেন।', 'button' => 'Shop the wood shop', 'button_bn' => 'কাঠের দোকান দেখুন', 'link' => '/market?sector=wood', 'items' => [
                    ['title' => 'Seasoned teak and sheesham', 'title_bn' => 'পোক্ত সেগুন ও শিশু', 'link' => '/market?sector=wood'],
                    ['title' => 'Woven and block-printed cloth', 'title_bn' => 'বোনা ও ব্লক-প্রিন্ট কাপড়', 'link' => '/market?sector=clothing'],
                    ['title' => 'Cast brass and hand-finished gold', 'title_bn' => 'ঢালাই পিতল ও হাতে সাজানো সোনা', 'link' => '/market?sector=jewellery'],
                ]]],
                ['rail', ['title' => 'Shop by occasion', 'title_bn' => 'উপলক্ষ অনুযায়ী কিনুন', 'text' => 'Start from the moment, not the shelf.', 'text_bn' => 'তাক নয়, উপলক্ষ দিয়ে শুরু করুন।', 'items' => [
                    ['title' => 'Eid', 'title_bn' => 'ঈদ', 'text' => '', 'link' => '/market?sector=clothing'],
                    ['title' => 'Weddings', 'title_bn' => 'বিয়ে', 'text' => '', 'link' => '/market?sector=jewellery'],
                    ['title' => 'New home', 'title_bn' => 'নতুন ঘর', 'text' => '', 'link' => '/market?sector=home'],
                    ['title' => 'Workday', 'title_bn' => 'কাজের দিন', 'text' => '', 'link' => '/market?sector=electronics'],
                    ['title' => 'Self care', 'title_bn' => 'নিজের যত্ন', 'text' => '', 'link' => '/market?sector=beauty'],
                    ['title' => 'Gifting', 'title_bn' => 'উপহার', 'text' => '', 'link' => '/market?all=1'],
                ]]],
                ['numbers', ['title' => 'The bazaar in numbers', 'title_bn' => 'সংখ্যায় বাজার', 'items' => [
                    $item('64', '৬৪', 'districts we deliver to', 'জেলায় আমরা পৌঁছাই'),
                    $item('7', '৭', 'days to return', 'দিনে ফেরত'),
                    $item('1', '১', 'price for every buyer', 'দাম সব ক্রেতার জন্য'),
                    $item('0', '০', 'hidden charges at the till', 'কাউন্টারে লুকানো খরচ'),
                ]]],
                ['products', ['title' => 'From the wood shop', 'title_bn' => 'কাঠের দোকান থেকে', 'source' => 'sector:wood', 'item_limit' => 4]],
                ['products', ['title' => 'Wear it well', 'title_bn' => 'ভালোভাবে পরুন', 'source' => 'sector:clothing', 'item_limit' => 4]],
                ['premium', ['title' => 'Premium picks', 'title_bn' => 'প্রিমিয়াম বাছাই', 'source' => 'premium', 'item_limit' => 4]],
                ['products', ['title' => 'Tech worth owning', 'title_bn' => 'রাখার মতো প্রযুক্তি', 'source' => 'sector:electronics', 'item_limit' => 4]],
                ['budget', ['title' => 'Shop by budget', 'title_bn' => 'বাজেট অনুযায়ী কিনুন', 'text' => 'Start from what you want to spend and see what fits.', 'text_bn' => 'কত খরচ করবেন তা দিয়ে শুরু করুন, কী মানায় দেখুন।']],
                ['products', ['title' => 'Care and home', 'title_bn' => 'যত্ন ও ঘর', 'source' => 'sector:beauty', 'item_limit' => 4]],
                ['sellers', ['title' => 'Sellers to trust', 'title_bn' => 'ভরসার বিক্রেতা', 'text' => 'Shops with a clean record and reviews you can read.', 'text_bn' => 'পরিষ্কার রেকর্ড আর পড়ার মতো রিভিউ আছে এমন দোকান।', 'item_limit' => 6]],
                ['reviews', ['title' => 'What buyers say', 'title_bn' => 'ক্রেতারা যা বলেন', 'item_limit' => 3]],
                ['steps', ['title' => 'How buying works', 'title_bn' => 'কেনাকাটা যেভাবে হয়', 'items' => [
                    $item('Choose', 'বেছে নিন', 'Browse the shops. The price you see is the price you pay.', 'দোকান ঘুরে দেখুন। যে দাম দেখেন সেটাই দিতে হয়।'),
                    $item('Order', 'অর্ডার করুন', 'Pay on delivery where the seller offers it, or pay online.', 'যেখানে বিক্রেতা দেন সেখানে ডেলিভারিতে দিন, অথবা অনলাইনে।'),
                    $item('Receive', 'হাতে পান', 'Delivery across Bangladesh. Follow the order from your orders page.', 'সারা বাংলাদেশে ডেলিভারি। অর্ডার পাতা থেকে অর্ডারের খবর দেখুন।'),
                ]]],
                ['service', ['items' => [
                    $item('One price for everyone', 'সবার জন্য এক দাম', 'No hidden extras at the till.', 'কাউন্টারে লুকানো খরচ নেই।'),
                    $item('Warranty shown', 'ওয়ারেন্টি দেখানো', 'Every product says what is covered before you order.', 'অর্ডারের আগেই প্রতিটি পণ্যে কী কভার হয় তা লেখা থাকে।'),
                    $item('Delivery across Bangladesh', 'সারা বাংলাদেশে ডেলিভারি', 'Dhaka and every district.', 'ঢাকা আর প্রতিটি জেলা।'),
                    $item('Reviews you can read', 'পড়ার মতো রিভিউ', 'Real reviews from people who bought.', 'যারা কিনেছেন তাদের আসল রিভিউ।'),
                ]]],
                ['faq', ['title' => 'Before you buy', 'title_bn' => 'কেনার আগে', 'items' => [
                    $item('Is the price the same for everyone?', 'দাম কি সবার জন্য এক?', 'Yes. The price shown on a product is the price for every buyer.', 'হ্যাঁ। পণ্যে দেখানো দাম সব ক্রেতার জন্য একই।'),
                    $item('Can I pay on delivery?', 'ডেলিভারিতে টাকা দেওয়া যায়?', 'Where the seller offers it. Each product shows whether cash on delivery is available.', 'যেখানে বিক্রেতা দেন। প্রতিটি পণ্যে ক্যাশ অন ডেলিভারি আছে কি না লেখা থাকে।'),
                    $item('What if something is not as described?', 'বর্ণনার সঙ্গে না মিললে?', 'Read the return policy on the product page, then ask for a return from your orders.', 'পণ্যের পাতায় ফেরত নীতি পড়ুন, তারপর আপনার অর্ডার থেকে ফেরত চান।'),
                ]]],
                ['cta', ['button' => 'Shop all {n} products', 'button_bn' => 'সব {n}টি পণ্য দেখুন']],
            ],
        };

        foreach ($rows as $i => [$kind, $fields]) {
            self::create(['page' => $page, 'kind' => $kind, 'sort' => ($i + 1) * 10] + $fields);
        }
    }
}
