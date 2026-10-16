<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * The pages a shop must have (spec ch 27): terms, privacy, how returns work, and answers to common questions. Open to
 * everyone, so none of it names a part of the platform that not everyone may know about. The words are plain on purpose;
 * the owner should have a lawyer read the terms and the privacy page before launch.
 */
class InfoController extends Controller
{
    public function terms(): Response
    {
        return $this->page('Terms of service', 'Please read these before you use the site or open an account.', [
            ['What this site is', [
                __('This site lists medicines and other products with their printed prices, and takes orders from people we have approved.'),
                __('The information on it is for your help. It is not medical advice. Always follow your doctor\'s prescription.'),
            ]],
            ['Your account', [
                __('You must give true details when you sign up, and keep your password to yourself.'),
                __('You are responsible for what is done from your account. Tell us at once if someone else has used it.'),
            ]],
            ['Prices and orders', [
                __('Prices shown are the printed prices unless your own price is shown after you sign in.'),
                __('An order is not a promise to supply. We check every item and tell you what we can supply, and what we cannot.'),
                __('Some medicines are sold only against a prescription, and some need a record of who bought them. We may ask for it.'),
            ]],
            ['Returns', [
                __('Medicine is generally not returnable. Read the return policy to see what can be returned and how.'),
            ]],
            ['Business accounts', [
                __('A business account is opened only after we have checked your licence. We may refuse an application, and we tell you why.'),
                __('We may suspend or close an account that breaks these terms, gives false details or misuses the site.'),
            ]],
            ['Changes and the law', [
                __('We may change these terms. The new version applies from the day it is shown here.'),
                __('These terms follow the laws of Bangladesh.'),
            ]],
        ]);
    }

    public function privacy(): Response
    {
        return $this->page('Privacy policy', 'What we keep about you, why, and who can see it.', [
            ['What we keep', [
                __('Your name, phone number, email, address and what you order.'),
                __('For a business account: the licence you send and your business details.'),
                __('For a pharmacy order: a prescription, only if you send one.'),
                __('When and from which device you sign in, so we can spot an account being misused.'),
                __('What you search for and look at on the site, without your name unless you are signed in, so we learn what people need.'),
            ]],
            ['Why we keep it', [
                __('To take and deliver your orders, to show you your own prices, and to keep a record the law asks us to keep.'),
                __('To keep the site safe: for example, to notice many failed sign-ins.'),
            ]],
            ['Who can see it', [
                __('Only our staff who need it for their work. What each of them can open depends on their role, and we keep a log of who opened what.'),
                __('Licences and prescriptions are private files. They are never shown on the site.'),
                __('We do not sell your details.'),
            ]],
            ['Cookies', [
                __('We use only the cookies the site needs to work: to keep you signed in and to remember your language.'),
            ]],
            ['Your choices', [
                __('You can ask us to correct your details or to close your account. Records the law asks us to keep, such as a controlled medicine sale, stay.'),
            ]],
        ]);
    }

    public function returns(): Response
    {
        return $this->page('Return policy', 'Medicine is generally not returnable. This is how it works.', [
            ['The rule', [
                __('Medicine is generally not returnable, because we cannot know how it was kept after it left us.'),
                __('If something is wrong with what you received, such as the wrong item or a damaged pack, tell us the same day.'),
            ]],
            ['How to ask', [
                __('Sign in, open your order and tell us what is wrong. You must give a reason, and you can send a voice note.'),
                __('We look at it and, if we approve, we call you. If you bought without signing in, open an account first.'),
            ]],
            ['What you get back', [
                __('We replace the item or give you credit for the price you paid. If you want cash instead, the amount we pay back is smaller, and we tell you how much before you agree.'),
            ]],
        ]);
    }

    public function faq(): Response
    {
        return $this->page('Questions and answers', 'The things people ask us most.', [
            ['Buying', [
                __('Can I order here? — Today you can search medicines and see their printed prices, and visit or call the pharmacy. Ordering for everyone is coming.'),
                __('Why is only one price shown? — It is the price printed on the pack. If you have an account that has its own price, you see that price after you sign in.'),
                __('Do I need a prescription? — Some medicines can only be sold with one. We ask when it is needed.'),
            ]],
            ['Finding things', [
                __('I could not find a medicine. — Tell us on the search page. We read every request and add what we can.'),
                __('Are medicine names translated? — No. Brand and company names always stay as they are printed.'),
                __('Can I use the site in Bangla? — Yes. Use the language button at the top of any page.'),
            ]],
            ['Accounts', [
                __('How do I open a business account? — Sign in, open Business account from the footer, tell us about your business and send your licence. We reply on the same page.'),
                __('Is my information safe? — Read the privacy page. Only the staff who need it can see your details.'),
            ]],
            ['Returns', [
                __('Can I return medicine? — Medicine is generally not returnable. Read the return policy for what can be done.'),
            ]],
        ]);
    }

    /** @param list<array{0: string, 1: list<string>}> $sections */
    private function page(string $title, string $intro, array $sections): Response
    {
        return Inertia::render('info', [
            'title' => __($title),
            'intro' => __($intro),
            'sections' => collect($sections)->map(fn (array $s) => ['heading' => __($s[0]), 'body' => $s[1]])->values(),
        ]);
    }
}
