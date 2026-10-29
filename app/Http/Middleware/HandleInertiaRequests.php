<?php

namespace App\Http\Middleware;

use App\Enums\AccountType;
use App\Enums\Section;
use App\Models\HelpPost;
use App\Models\Listing;
use App\Models\MarketOrder;
use App\Models\Notice;
use App\Models\ProductQuestion;
use App\Models\Setting;
use App\Models\TradeApplication;
use App\Services\Sales\ApprovalService;
use App\Services\Sales\PreOrders;
use App\Services\Security\SocialLogin;
use App\Services\Site\Site;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            // One brand source (config/brand.php), already in the viewer's language.
            'brand' => fn () => self::brand(),
            // The ways of signing in with an outside account that the owner switched on (the buttons on the sign-in page).
            'socialProviders' => fn () => $request->user() ? [] : app(SocialLogin::class)->enabled(),
            'site' => fn () => app(Site::class)->forViewer()['site'],
            // Someone who sells gets a way across to the supplier app (one login for both apps).
            'supplierApp' => fn () => app(\App\Services\Supplier\SupplierApp::class)->isSupplier($request->user()),
            // An affiliate with an account is shown a link to their own page.
            'isAffiliate' => fn () => $request->user() ? \App\Models\Affiliate::where('user_id', $request->user()->id)->exists() : false,
            // The public key a browser needs to say yes to notifications; only for a signed-in person, and only when the owner switched it on.
            'push' => fn () => $request->user() ? ['key' => app(\App\Services\Push\Notifications::class)->publicKey()] : null,
            // The visitor-statistics ids the owner switched on, or nothing (staff and the admin screens are never counted).
            'analytics' => fn () => app(\App\Services\Analytics\Tags::class)->forRequest($request),
            'locale' => fn () => app()->getLocale(),
            // English is the source text, so only Bangla needs a dictionary sent to the browser.
            // The dictionary is a few hundred kilobytes, so a page the app moves to (not a full load) leaves it out when the browser says
            // it already holds this very one (`X-Dictionary` is the key it was sent with); the key changes with the language, the kind of
            // viewer (a login changes what they may read) and the dictionary file itself.
            'dictKey' => fn () => self::dictKey($request),
            'translations' => fn () => app()->getLocale() !== 'bn'
                ? (object) []
                : ($request->header('X-Inertia') && $request->header('X-Dictionary') === self::dictKey($request) ? null : self::dictionaryFor($request)),
            'flash' => ['status' => fn () => $request->session()->get('status')],
            // How many different items are in the public cart (the badge on the cart button).
            // The public cart is for visitors and plain accounts; a shop or a bulk buyer orders from their own screen.
            'canCart' => fn () => $request->user() === null || $request->user()->account_type === AccountType::Public,
            // A notice the owner writes to announce an update or a closed day in advance (spec ch 28 note 11). Shown in the language the visitor reads.
            'siteNotice' => fn () => (app()->getLocale() === 'bn' ? (string) Setting::read('notice.text_bn', '') : '') ?: ((string) Setting::read('notice.text', '') ?: null),
            // A selling account's own business name, so its screens feel like its own (spec ch 14: white-label). Only that account ever gets it.
            'sellerBrand' => fn () => $request->user()?->account_type === AccountType::Wholesaler ? ($request->user()->business_name ?: null) : null,
            'cartCount' => fn () => $request->hasSession() ? count((array) $request->session()->get('cart', [])) : 0,
            'auth' => [
                // Only what the shell needs. The account type is not sent: every decision that
                // depends on it is made on the server (spec G2).
                'user' => $request->user()?->only(['id', 'name', 'email', 'email_verified_at']),
                // Server-decided capability flags. The UI only uses them to hide links;
                // every route still enforces access on the server.
                'pending' => $request->user()?->can('decide-approvals') ? app(ApprovalService::class)->pendingCount() : 0,
                // Pre-orders waiting for an answer, or past their time.
                'preorders' => $request->user()?->can('handle-orders') ? app(PreOrders::class)->attention() : 0,
                // Unread messages in a customer's inbox; nothing for guests, staff or the owner.
                'inbox' => $request->user() && in_array($request->user()->account_type, [AccountType::Public, AccountType::Paikari, AccountType::Wholesaler], true)
                    ? Notice::where('user_id', $request->user()->id)->whereNull('read_at')->count() : null,
                'sellerOrders' => $request->user()?->account_type === AccountType::Wholesaler ? MarketOrder::where('seller_id', $request->user()->id)->whereNull('seller_seen_at')->count() : 0,
                'listingsPending' => $request->user()?->can('manage-customers') ? Listing::where('status', 'pending')->count() : 0,
                'helpPending' => $request->user()?->can('manage-customers') ? HelpPost::where('status', 'pending')->count() : 0,
                'questions' => $request->user()?->can('handle-orders') ? ProductQuestion::waiting()->count() : 0,
                'applications' => $request->user()?->can('manage-customers') ? TradeApplication::where('status', 'pending')->count() : 0,
                // A plain account may open the application page; nobody else needs the link.
                'canApply' => $request->user()?->account_type === AccountType::Public,
                'can' => [
                    'manageCustomers' => (bool) $request->user()?->can('manage-customers'),
                    'useCounter' => (bool) $request->user()?->can('use-counter'),
                    'manageGoods' => (bool) $request->user()?->can('manage-stock'),
                    'viewReports' => (bool) $request->user()?->can('view-reports'),
                    'manageOffers' => (bool) $request->user()?->can('manage-offers'),
                    'approveReturns' => (bool) $request->user()?->can('approve-returns'),
                    'decideApprovals' => (bool) $request->user()?->can('decide-approvals'),
                    'manageAccounts' => (bool) $request->user()?->can('manage-accounts'),
                    'handleOrders' => (bool) $request->user()?->can('handle-orders'),
                    'recordReturns' => (bool) $request->user()?->can('record-returns'),
                    'makeDeliveries' => (bool) $request->user()?->can('make-deliveries'),
                ],
            ],
        ]);
    }

    /** @return array{name: string, logo: ?string, phone: ?string, email: ?string, address: ?string, whatsapp: ?string, messenger: ?string, drug_license: ?string} */
    public static function brand(): array
    {
        return app(Site::class)->forViewer()['brand'];
    }

    /**
     * The Bangla dictionary for this viewer, from lang/bn.json (English text => Bangla text).
     *
     * Golden rule G1: a visitor must never learn that restricted sections exist, and the dictionary is
     * sent to the browser with every page. So a string that names a restricted section is only included
     * when the viewer may see that section. A guest gets none of them; a paikari buyer never gets the
     * wholesale ones; only the admin gets all.
     *
     * @return array<string, string>
     */
    private static function dictKey(Request $request): string
    {
        $file = lang_path('bn.json');

        return md5(app()->getLocale().'|'.($request->user()?->account_type?->value ?? 'guest').'|'.@filemtime($file).'|'.@filesize($file));
    }

    private static function dictionaryFor(Request $request): array
    {
        static $all = null;
        $all ??= json_decode((string) file_get_contents(lang_path('bn.json')), true) ?: [];

        $type = $request->user()?->account_type ?? AccountType::Public;

        return array_filter($all, function (string $_, string $key) use ($type) {
            // Words a buyer or a seller reads on their own screens, even though they use a staff-only word.
            if (in_array($key, self::SAFE_FOR_EVERYONE, true)) {
                return true;
            }

            $lower = mb_strtolower($key);

            foreach (self::RESTRICTED_WORDS as $word => $section) {
                if (preg_match('/\b'.preg_quote($word, '/').'\b/u', $lower) && ! $type->canSee($section)) {
                    return false;
                }
            }

            return true;
        }, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Phrases that contain a staff-only word (stock, cost) but are for buyers and sellers: "In stock" is what a shop is told about a product.
     * Without this they were held back and a Bangla screen showed them in English.
     */
    private const SAFE_FOR_EVERYONE = [
        'In stock',
        'In stock · {n} boxes',
        'It is not in stock now. The seller will have it ready within {n} days.',
        'It is not in stock now. Your pre-order is kept and the seller sends it when they have it again.',
        'Was on offer a moment ago. Stock out.',
        'Noted. We will try to stock “{query}”.',
        'Noted. We will try to stock it.',
        'You made {amount} after your own cost',
        'Your own cost for one (only you see it)',
    ];

    /** Words that name a section not everyone may know about, and the section a viewer needs to see them. */
    private const RESTRICTED_WORDS = [
        'paikari' => Section::Paikari,
        'wholesale' => Section::Wholesale,
        'mpo' => Section::Mpo,
        'craftsman' => Section::Craftsman,
        // Staff-only language never reaches a shop or a visitor.
        'flagged' => Section::AdminPanel,
        'stock' => Section::AdminPanel,
        'purchase' => Section::AdminPanel,
        'cost' => Section::AdminPanel,
    ];
}
