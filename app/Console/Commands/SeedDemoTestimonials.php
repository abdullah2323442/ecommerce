<?php

namespace App\Console\Commands;

use App\Models\Testimonial;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Sample testimonials for a development shop, so the home page has something to show. The names and quotes are invented: they are placeholders
 * the owner replaces with real buyers' words in Admin > Testimonials, and the command refuses to run on a production server.
 * Safe to run again: a quote already there is updated, never doubled.
 */
class SeedDemoTestimonials extends Command
{
    protected $signature = 'demo:testimonials {--force : Allow it on a production server}';

    protected $description = 'Add sample testimonials for the home page (development only)';

    /** [name, place, quote, quote in Bangla, stars] */
    private const ROWS = [
        ['Nusrat Jahan', 'Dhanmondi, Dhaka', 'The teak chair arrived wrapped like a gift and looks even better than the photo. I paid the rider and that was it.', 'সেগুনের চেয়ারটা উপহারের মতো মুড়িয়ে এসেছে, ছবির চেয়েও সুন্দর। রাইডারকে টাকা দিলাম, ব্যস।', 5],
        ['Tanvir Hasan', 'Agrabad, Chattogram', 'Same price on the page and at the door. No surprise charge at the end, which is rare for an online shop.', 'পাতায় যে দাম, দরজাতেও সেই দাম। শেষে বাড়তি কোনো চার্জ নেই, অনলাইন দোকানে এটা বিরল।', 5],
        ['Farhana Akter', 'Zindabazar, Sylhet', 'I ordered a linen kurta for Eid. The fit was right and the cloth is cool in the heat. Ordering again for my brother.', 'ঈদের জন্য একটা লিনেন কুর্তা নিয়েছিলাম। মাপ ঠিক, গরমে আরামদায়ক। ভাইয়ের জন্য আবার অর্ডার দেব।', 5],
        ['Mahmudul Karim', 'Boalia, Rajshahi', 'Something was wrong with my power bank. I wrote to support in Bangla and a real person sorted it out in a day.', 'আমার পাওয়ার ব্যাংকে সমস্যা ছিল। বাংলায় লিখলাম, একজন মানুষ একদিনেই সমাধান করে দিলেন।', 4],
        ['Sadia Rahman', 'Uttara, Dhaka', 'The Sundarbans honey is the real thing. You can taste the difference from the supermarket jars.', 'সুন্দরবনের মধু খাঁটি। সুপারমার্কেটের বোতলের সাথে স্বাদের পার্থক্য বোঝা যায়।', 5],
        ['Rakibul Islam', 'Sadar, Khulna', 'Topped up my game in under a minute and the receipt came straight away. Fair prices too.', 'এক মিনিটের মধ্যে গেম টপ-আপ হয়ে গেছে, রসিদও সাথে সাথে পেয়েছি। দামও ন্যায্য।', 5],
        ['Mitu Chowdhury', 'Kotwali, Cumilla', 'I search medicines by generic name here before I go to the pharmacy. It saves me a lot of guessing.', 'ফার্মেসিতে যাওয়ার আগে এখানে জেনেরিক নামে ওষুধ খুঁজে নিই। অনেক অনুমানের ঝামেলা বাঁচে।', 5],
        ['Arif Hossain', 'Rangpur Sadar', 'Nobody at home knew the shop, but the reviews and the checked sellers gave me the confidence to try. Glad I did.', 'বাড়িতে কেউ দোকানটা চিনত না, কিন্তু রিভিউ আর যাচাই করা বিক্রেতা দেখে ভরসা পেলাম। ভালো সিদ্ধান্ত ছিল।', 4],
    ];

    public function handle(): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('Refusing to add sample testimonials on a production server. Use --force if you really mean it.');

            return self::FAILURE;
        }

        foreach (self::ROWS as $i => [$name, $place, $quote, $quoteBn, $rating]) {
            Testimonial::updateOrCreate(['name' => $name, 'quote' => $quote], ['place' => $place, 'quote_bn' => $quoteBn, 'rating' => $rating, 'is_active' => true, 'sort' => $i + 1]);
        }

        Cache::forget('home.testimonials');
        $this->info('Added or updated '.count(self::ROWS).' sample testimonials.');

        return self::SUCCESS;
    }
}
