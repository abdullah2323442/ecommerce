<?php

use App\Http\Controllers\Admin\ApplicationController;
use App\Http\Controllers\Admin\ApprovalController;
use App\Http\Controllers\Admin\AffiliateController;
use App\Http\Controllers\Admin\ArchiveController;
use App\Http\Controllers\Admin\PharmaTrackController as AdminPharmaTrackController;
use App\Http\Controllers\Admin\RxInterestController as AdminRxInterestController;
use App\Http\Controllers\Admin\ServiceOfferController as AdminServiceOfferController;
use App\Http\Controllers\Admin\BehaviorController;
use App\Http\Controllers\Admin\BinController;
use App\Http\Controllers\Admin\BlockedIpController;
use App\Http\Controllers\Admin\CatalogController as AdminCatalogController;
use App\Http\Controllers\Admin\ControlledController;
use App\Http\Controllers\Admin\CounterController;
use App\Http\Controllers\Admin\CourierBookingController;
use App\Http\Controllers\Admin\CourierController;
use App\Http\Controllers\Admin\CraftsmanController as AdminCraftsmanController;
use App\Http\Controllers\Admin\CustomChatController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\CustomerImportController;
use App\Http\Controllers\Admin\SmsController;
use App\Http\Controllers\Admin\CustomOrderController as AdminCustomOrderController;
use App\Http\Controllers\Admin\DayClosingController;
use App\Http\Controllers\Admin\DeliveryController as AdminDeliveryController;
use App\Http\Controllers\Admin\DepositController;
use App\Http\Controllers\Admin\ExpiryController;
use App\Http\Controllers\Admin\FoodController as AdminFoodController;
use App\Http\Controllers\Admin\GamingController as AdminGamingController;
use App\Http\Controllers\Admin\HelpController as AdminHelpController;
use App\Http\Controllers\Admin\IntegrationController as AdminIntegrationController;
use App\Http\Controllers\Admin\InvoiceController;
use App\Http\Controllers\Admin\LedgerController;
use App\Http\Controllers\Admin\MarketController as AdminMarketController;
use App\Http\Controllers\Admin\MedicineController as AdminMedicineController;
use App\Http\Controllers\Admin\MessagesController;
use App\Http\Controllers\Admin\MismatchController;
use App\Http\Controllers\Admin\MpoController;
use App\Http\Controllers\Admin\OfferController;
use App\Http\Controllers\Admin\OfflineCounterController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\PayablesController;
use App\Http\Controllers\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Admin\PreorderController as AdminPreorderController;
use App\Http\Controllers\Admin\PrescriptionController;
use App\Http\Controllers\Admin\ProductRequestController as AdminProductRequestController;
use App\Http\Controllers\Admin\QuestionController as AdminQuestionController;
use App\Http\Controllers\Admin\QuoteController as AdminQuoteController;
use App\Http\Controllers\Admin\ReturnController;
use App\Http\Controllers\Admin\ReviewController as AdminReviewController;
use App\Http\Controllers\Admin\SalesReportController;
use App\Http\Controllers\Admin\SellingRateController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SiteController as AdminSiteController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Admin\StockController;
use App\Http\Controllers\Admin\StockCountController;
use App\Http\Controllers\Admin\StockReportController;
use App\Http\Controllers\Admin\StockSourceController;
use App\Http\Controllers\Admin\StorefrontController as AdminStorefrontController;
use App\Http\Controllers\Admin\TestimonialController as AdminTestimonialController;
use App\Http\Controllers\Admin\TierController;
use App\Http\Controllers\Admin\TokenController as AdminTokenController;
use App\Http\Controllers\Admin\TwoFactorResetController;
use App\Http\Controllers\Admin\VatReportController;
use App\Http\Controllers\Admin\WeightController;
use App\Http\Controllers\Auth\SocialLoginController;
use App\Http\Controllers\BrandImageController;
use App\Http\Controllers\BusinessApplicationController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CourierWebhookController;
use App\Http\Controllers\CraftsmanController;
use App\Http\Controllers\CustomOrderController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FoodController;
use App\Http\Controllers\FoodVendorController;
use App\Http\Controllers\FormController;
use App\Http\Controllers\GamingController;
use App\Http\Controllers\HelpController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\InfoController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\MarketCartController;
use App\Http\Controllers\MarketController;
use App\Http\Controllers\MarketOfferController;
use App\Http\Controllers\MarketOrderController;
use App\Http\Controllers\MeasurementProfileController;
use App\Http\Controllers\MedicineSearchController;
use App\Http\Controllers\MemoController;
use App\Http\Controllers\MpoOfferController;
use App\Http\Controllers\MpoPortalController;
use App\Http\Controllers\OrderHistoryController;
use App\Http\Controllers\PaikariController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PreorderController;
use App\Http\Controllers\ProductRequestController;
use App\Http\Controllers\PublicOrderController;
use App\Http\Controllers\PwaController;
use App\Http\Controllers\QuestionController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\SellController;
use App\Http\Controllers\AffiliateLinkController;
use App\Http\Controllers\PharmaTrackController;
use App\Http\Controllers\PushController;
use App\Http\Controllers\RxMarketController;
use App\Http\Controllers\SellDealsController;
use App\Http\Controllers\SellServicesController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\StopTextsController;
use App\Http\Controllers\SupplierAppController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\SiteSearchController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\WholesaleController;
use App\Http\Controllers\WishlistController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Public home page (spec ch 13). Real data only; no mention of any restricted section.
Route::get('/', HomeController::class)->name('home');

// What search engines may find (spec ch 27: SEO): only what any visitor can already open.
Route::get('robots.txt', [SitemapController::class, 'robots'])->name('robots');
Route::get('sitemap.xml', [SitemapController::class, 'index'])->name('sitemap.index');
Route::get('sitemap-pages.xml', [SitemapController::class, 'pages'])->name('sitemap.pages');
Route::get('sitemap-medicines-{page}.xml', [SitemapController::class, 'medicines'])->whereNumber('page')->name('sitemap.medicines');

// Medicine database: open to everyone (spec ch 11). MRP only; never any stock information.
Route::get('search', [SiteSearchController::class, 'index'])->middleware('throttle:60,1,site-search')->name('search');
Route::get('medicines', [MedicineSearchController::class, 'index'])
    ->middleware('throttle:90,1,medicine-search')
    ->name('medicines.index');
Route::get('medicines/suggest', [MedicineSearchController::class, 'suggest'])
    ->middleware('throttle:60,1,medicine-suggest')
    ->name('medicines.suggest');
Route::get('medicines/{medicine}', [MedicineSearchController::class, 'show'])->name('medicines.show');
// Questions under a product (spec ch 23): signed-in buyers ask, everyone reads the answer.
Route::post('medicines/{medicine}/questions', [QuestionController::class, 'store'])->middleware(['auth', 'throttle:6,1,question'])->name('medicines.questions.store');
Route::post('locale', LocaleController::class)->name('locale');
Route::post('requests', [ProductRequestController::class, 'store'])
    ->middleware('throttle:6,1,product-request')
    ->name('requests.store');
// Pages every shop must have (spec ch 27).
Route::get('terms', [InfoController::class, 'terms'])->name('info.terms');
Route::get('privacy', [InfoController::class, 'privacy'])->name('info.privacy');
Route::get('return-policy', [InfoController::class, 'returns'])->name('info.returns');
Route::get('faq', [InfoController::class, 'faq'])->name('info.faq');
Route::get('forms/{slug}', [FormController::class, 'show'])->name('forms.show');
// The market beyond medicine (spec ch 13): open to everyone, each viewer sees their own prices. A product that is not for this
// viewer sends them quietly home.
Route::get('market', [MarketController::class, 'index'])->name('market.index');
Route::get('market/photo/{listing}', [MarketController::class, 'photo'])->whereNumber('listing')->name('market.photo');
Route::get('market/{listing}', [MarketController::class, 'show'])->whereNumber('listing')->name('market.show');
Route::post('market/{listing}/report', [MarketController::class, 'report'])->whereNumber('listing')->middleware(['auth', 'throttle:10,1,market-report'])->name('market.report');
// Coming back from Google or Facebook. Public on purpose: the person may or may not be signed in yet.
Route::get('auth/{provider}/callback', [SocialLoginController::class, 'callback'])->whereIn('provider', ['google', 'facebook'])->middleware('throttle:30,1,social-callback')->name('social.callback');
Route::post('webhooks/courier/{provider}', [CourierWebhookController::class, 'receive'])->whereIn('provider', ['steadfast'])->middleware('throttle:120,1,courier-webhook')->name('courier.webhook');
// Paying for an order online: start from the order's private page; the gateway's return and its own message only start a check.
Route::post('orders/{token}/pay', [PaymentController::class, 'start'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:10,1,order-pay')->name('orders.pay');
Route::post('market/orders/{token}/pay', [PaymentController::class, 'startMarket'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:10,1,market-pay')->name('market.order.pay');
Route::match(['get', 'post'], 'webhooks/payments/{provider}/result/{result}', [PaymentController::class, 'result'])->whereIn('provider', ['sslcommerz'])->whereIn('result', ['success', 'fail', 'cancel'])->middleware('throttle:60,1,payment-return')->name('payments.result');
Route::post('webhooks/payments/{provider}/ipn', [PaymentController::class, 'ipn'])->whereIn('provider', ['sslcommerz'])->middleware('throttle:120,1,payment-ipn')->name('payments.ipn');
// The installable app: manifest, icons, service worker and the no-line page.
// PharmaTrack (spec item 612) writes here with its own password: the stock, its sales and its counts. No page, no session.
Route::prefix('webhooks/pharmatrack')->middleware(['pharmatrack', 'throttle:240,1,pharmatrack'])->name('pharmatrack.')->group(function () {
    Route::get('ping', [PharmaTrackController::class, 'ping'])->name('ping');
    Route::get('stock', [PharmaTrackController::class, 'stock'])->name('stock');
    Route::post('sales', [PharmaTrackController::class, 'sales'])->name('sales');
    Route::post('counts', [PharmaTrackController::class, 'counts'])->name('counts');
    Route::post('adjustments', [PharmaTrackController::class, 'adjustments'])->name('adjustments');
});
// The prescription marketplace (item 577), a placeholder: what it will be, and a list of people who want it.
Route::get('prescription-market', [RxMarketController::class, 'show'])->name('rx-market');
Route::post('prescription-market/interest', [RxMarketController::class, 'interest'])->middleware('throttle:10,1,rx-interest')->name('rx-market.interest');
// Services anyone can book (item 613): a provider lists times, a buyer asks for one, the provider answers.
Route::get('services', [ServiceController::class, 'index'])->name('services.index');
Route::get('services/booking/{token}', [ServiceController::class, 'booking'])->where('token', '[A-Za-z0-9]{40}')->name('services.booking');
Route::post('services/booking/{token}/cancel', [ServiceController::class, 'cancel'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:20,1,service-cancel')->name('services.cancel');
Route::get('services/{offer}', [ServiceController::class, 'show'])->whereNumber('offer')->name('services.show');
Route::post('services/{offer}/book', [ServiceController::class, 'book'])->whereNumber('offer')->middleware('throttle:10,1,service-book')->name('services.book');
// An affiliate's link: keeps their code on the visitor for a while, then sends them on.
Route::get('a/{code}', [AffiliateLinkController::class, 'visit'])->where('code', '[A-Za-z0-9]{3,24}')->middleware('throttle:60,1,affiliate-click')->name('affiliate.visit');
Route::get('affiliate', [AffiliateLinkController::class, 'mine'])->middleware('auth')->name('affiliate.mine');
// The link at the end of a promotional text: stop these messages (a button first, so a link preview cannot do it).
Route::get('u/{user}/{token}', [StopTextsController::class, 'show'])->whereNumber('user')->where('token', '[a-f0-9]{12}')->name('texts.stop');
Route::post('u/{user}/{token}', [StopTextsController::class, 'stop'])->whereNumber('user')->where('token', '[a-f0-9]{12}')->middleware('throttle:20,1,texts-stop')->name('texts.stop.do');
Route::get('manifest.webmanifest', [PwaController::class, 'manifest'])->name('pwa.manifest');
// The supplier app (spec ch 3): the same website installed a second time, for people who sell. Same login as the customer app.
Route::get('supplier/manifest.webmanifest', [SupplierAppController::class, 'manifest'])->name('pwa.supplier.manifest');
Route::get('supplier', [SupplierAppController::class, 'home'])->middleware('auth')->name('supplier.home');
Route::get('pwa/icon/{name}.png', [PwaController::class, 'icon'])->whereIn('name', ['192', '512', 'maskable-512', 'apple-180'])->name('pwa.icon');
Route::get('sw.js', [PwaController::class, 'worker'])->name('pwa.worker');
Route::get('offline', [PwaController::class, 'offline'])->name('pwa.offline');
Route::get('sector-hero/{slug}', [MarketController::class, 'hero'])->where('slug', '[a-z0-9_]+')->name('market.hero');
Route::get('storefront-image/{block}', [MarketController::class, 'storefrontImage'])->whereNumber('block')->name('market.storefront-image');
Route::get('storefront-item-image/{block}/{index}', [MarketController::class, 'storefrontItemImage'])->whereNumber(['block', 'index'])->name('market.storefront-item-image');
Route::get('site-image/{name}', [BrandImageController::class, 'block'])->where('name', '[A-Za-z0-9_.-]+')->name('site.block-image');
Route::get('brand/{which}', [BrandImageController::class, 'show'])->whereIn('which', ['logo', 'favicon'])->name('brand.image');
Route::get('shop/{seller}', [MarketController::class, 'shop'])->whereNumber('seller')->name('shop.show');
// A friend's referral link (spec ch 24): the code is kept for sign-up, then the visitor lands on the register page.
Route::get('r/{code}', function (Request $request, string $code) {
    if (preg_match('/^[A-Za-z0-9]{6,10}$/', $code)) {
        $request->session()->put('referral_code', strtoupper($code));
    }

    return redirect('/register');
})->name('referral.link');
Route::get('history', [OrderHistoryController::class, 'index'])->middleware('auth')->name('history');
Route::get('market-cart', [MarketCartController::class, 'show'])->name('market.cart');
Route::post('market-cart', [MarketCartController::class, 'add'])->middleware('throttle:60,1,market-cart')->name('market.cart.add');
Route::post('market-cart/bundle/{bundle}', [MarketCartController::class, 'addBundle'])->whereNumber('bundle')->middleware('throttle:30,1,market-cart')->name('market.cart.bundle');
Route::post('market-cart/checkout', [MarketCartController::class, 'checkout'])->middleware('throttle:10,1,market-checkout')->name('market.cart.checkout');
Route::put('market-cart/{key}', [MarketCartController::class, 'update'])->where('key', '[0-9]+:[0-9]+')->name('market.cart.update');
Route::delete('market-cart/{key}', [MarketCartController::class, 'remove'])->where('key', '[0-9]+:[0-9]+')->name('market.cart.remove');
Route::get('market/packages/{token}', [MarketCartController::class, 'packages'])->where('token', '[A-Za-z0-9]{40}')->name('market.packages');
Route::get('wishlist', [WishlistController::class, 'index'])->middleware('auth')->name('wishlist');
Route::post('market/{listing}/wishlist', [WishlistController::class, 'toggle'])->whereNumber('listing')->middleware(['auth', 'throttle:60,1,wishlist'])->name('wishlist.toggle');
Route::post('shop/{seller}/follow', [MarketController::class, 'follow'])->whereNumber('seller')->middleware(['auth', 'throttle:20,1,shop-follow'])->name('shop.follow');
Route::delete('shop/{seller}/follow', [MarketController::class, 'unfollow'])->whereNumber('seller')->middleware('auth')->name('shop.unfollow');
Route::get('market/video/{listing}', [MarketController::class, 'video'])->whereNumber('listing')->name('market.video');
Route::get('market/extra-photo/{photo}', [MarketController::class, 'extraPhoto'])->whereNumber('photo')->name('market.extra-photo');
Route::get('market/review-photo/{photo}', [MarketController::class, 'reviewPhoto'])->whereNumber('photo')->name('market.review-photo');
Route::post('market/orders/{token}/review', [MarketOrderController::class, 'review'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:6,1,market-review')->name('market.order.review');
Route::get('market/variant-photo/{variant}', [MarketController::class, 'variantPhoto'])->whereNumber('variant')->name('market.variant-photo');
Route::post('market/{listing}/why', [MarketOfferController::class, 'why'])->whereNumber('listing')->middleware(['auth', 'throttle:10,1,market-why'])->name('market.why');
Route::post('market/{listing}/questions', [MarketController::class, 'ask'])->whereNumber('listing')->middleware(['auth', 'throttle:6,1,market-question'])->name('market.ask');
Route::post('market/{listing}/order', [MarketOrderController::class, 'store'])->whereNumber('listing')->middleware('throttle:10,1,market-order')->name('market.order.store');
Route::get('market/orders/{token}', [MarketOrderController::class, 'show'])->where('token', '[A-Za-z0-9]{40}')->name('market.order');
Route::get('market/orders/{token}/download', [MarketOrderController::class, 'download'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:20,1,market-download')->name('market.order.download');
Route::post('market/orders/{token}/cancel', [MarketOrderController::class, 'cancel'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:10,1,market-cancel')->name('market.order.cancel');
Route::post('market/orders/{token}/received', [MarketOrderController::class, 'received'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:10,1,market-received')->name('market.order.received');
Route::post('market/orders/{token}/not-received', [MarketOrderController::class, 'notReceived'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:10,1,market-notreceived')->name('market.order.not-received');
Route::post('market/orders/{token}/case', [MarketOrderController::class, 'openCase'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:10,1,market-case')->name('market.order.case');
Route::post('market/orders/{token}/cases/{case}/escalate', [MarketOrderController::class, 'escalateCase'])->where('token', '[A-Za-z0-9]{40}')->whereNumber('case')->middleware('throttle:10,1,market-case')->name('market.order.case.escalate');
// Made to order (spec ch 16): wood and clothing to the buyer's own measurements. Signed in, because the measurements are saved to the account.
Route::middleware('auth')->prefix('custom')->name('custom.')->group(function () {
    Route::get('/', [CustomOrderController::class, 'index'])->name('index');
    Route::get('profiles', [MeasurementProfileController::class, 'index'])->name('profiles');
    Route::post('profiles', [MeasurementProfileController::class, 'store'])->middleware('throttle:30,1,profiles')->name('profiles.store');
    Route::put('profiles/{profile}', [MeasurementProfileController::class, 'update'])->whereNumber('profile')->name('profiles.update');
    Route::put('profiles/{profile}/still-correct', [MeasurementProfileController::class, 'stillCorrect'])->whereNumber('profile')->name('profiles.still-correct');
    Route::delete('profiles/{profile}', [MeasurementProfileController::class, 'destroy'])->whereNumber('profile')->name('profiles.destroy');
    Route::get('new/{kind}', [CustomOrderController::class, 'create'])->where('kind', 'wood|clothing')->name('new');
    Route::post('/', [CustomOrderController::class, 'store'])->middleware('throttle:10,1,custom-order')->name('store');
    Route::get('{token}', [CustomOrderController::class, 'show'])->where('token', '[A-Za-z0-9]{40}')->name('show');
    Route::get('{token}/photo', [CustomOrderController::class, 'photo'])->where('token', '[A-Za-z0-9]{40}')->name('photo');
    Route::post('{token}/chat', [CustomOrderController::class, 'chat'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:60,1,custom-chat')->name('chat');
    Route::post('{token}/chat/measurements', [CustomOrderController::class, 'measurements'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:20,1,custom-chat')->name('chat.measurements');
    Route::post('{token}/chat/{message}/offer', [CustomOrderController::class, 'offer'])->where('token', '[A-Za-z0-9]{40}')->whereNumber('message')->name('chat.offer');
    Route::get('{token}/chat/{message}/file', [CustomOrderController::class, 'chatFile'])->where('token', '[A-Za-z0-9]{40}')->whereNumber('message')->name('chat.file');
    Route::post('{token}/confirm', [CustomOrderController::class, 'confirm'])->where('token', '[A-Za-z0-9]{40}')->name('confirm');
    Route::post('{token}/cancel', [CustomOrderController::class, 'cancel'])->where('token', '[A-Za-z0-9]{40}')->name('cancel');
    Route::post('{token}/complain', [CustomOrderController::class, 'complain'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:10,1,custom-complain')->name('complain');
});
Route::get('market-orders', [MarketOrderController::class, 'index'])->middleware('auth')->name('market.orders');

// Support tickets (spec ch 29): anyone writes to the shop, name and phone needed; the private link follows the answer.
Route::get('support/new', [SupportController::class, 'create'])->name('support.create');
Route::post('support', [SupportController::class, 'store'])->middleware('throttle:5,60,support-new')->name('support.store');
Route::get('support/t/{token}', [SupportController::class, 'show'])->where('token', '[A-Za-z0-9]{40}')->name('support.show');
Route::post('support/t/{token}/reply', [SupportController::class, 'reply'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:20,60,support-reply')->name('support.reply');
Route::get('support', [SupportController::class, 'index'])->middleware('auth')->name('support.index');

// The Help board (spec ch 19): anyone reads, signed-in people post, comment and report. Every post is approved first.
Route::get('help', [HelpController::class, 'index'])->name('help.index');
Route::middleware('auth')->group(function () {
    Route::get('help/new', [HelpController::class, 'create'])->name('help.create');
    Route::post('help', [HelpController::class, 'store'])->middleware('throttle:6,1,help-post')->name('help.store');
    Route::get('help/mine', [HelpController::class, 'mine'])->name('help.mine');
    Route::post('help/{post}/close', [HelpController::class, 'close'])->name('help.close');
    Route::post('help/{post}/confirm', [HelpController::class, 'confirm'])->name('help.confirm');
    Route::post('help/{post}/comments', [HelpController::class, 'comment'])->middleware('throttle:10,1,help-comment')->name('help.comment');
    Route::post('help/{post}/report', [HelpController::class, 'report'])->middleware('throttle:6,1,help-report')->name('help.report');
});
Route::get('help/{post}', [HelpController::class, 'show'])->whereNumber('post')->name('help.show');
Route::get('companies', [CompanyController::class, 'index'])->name('companies.index');
Route::get('companies/{company}', [CompanyController::class, 'show'])->name('companies.show');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
});

// The public cart, checkout and order tracking (spec ch 13): no account needed; the private link follows the order.
Route::get('cart', [CartController::class, 'show'])->name('cart.show');
Route::post('cart', [CartController::class, 'add'])->middleware('throttle:60,1,cart')->name('cart.add');
Route::put('cart/{key}', [CartController::class, 'update'])->where('key', '[0-9]+:(box|strip|piece)')->name('cart.update');
Route::delete('cart/{key}', [CartController::class, 'remove'])->where('key', '[0-9]+:(box|strip|piece)')->name('cart.remove');
Route::get('checkout', [CheckoutController::class, 'show'])->name('checkout.show');
Route::get('location/lookup', [LocationController::class, 'lookup'])->middleware('throttle:20,1,location')->name('location.lookup');
Route::get('checkout/quote', [CheckoutController::class, 'quote'])->middleware('throttle:120,1,checkout-quote')->name('checkout.quote');
Route::post('checkout', [CheckoutController::class, 'place'])->middleware('throttle:6,1,checkout')->name('checkout.place');
Route::get('orders/{token}', [PublicOrderController::class, 'show'])->where('token', '[A-Za-z0-9]{40}')->name('orders.show');
Route::post('orders/{token}/received', [PublicOrderController::class, 'received'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:10,1,order-received')->name('orders.received');
Route::post('orders/{token}/not-received', [PublicOrderController::class, 'notReceived'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:10,1,order-received')->name('orders.not-received');
Route::put('orders/{token}/address', [PublicOrderController::class, 'address'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:10,1,order-address')->name('orders.address');
Route::post('orders/{token}/note', [PublicOrderController::class, 'note'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:10,1,order-note')->name('orders.note');
Route::post('orders/{token}/rate', [PublicOrderController::class, 'rate'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:6,1,order-rate')->name('orders.rate');
Route::put('orders/{token}/lines/{line}', [PublicOrderController::class, 'changeLine'])->where('token', '[A-Za-z0-9]{40}')->whereNumber('line')->middleware('throttle:20,1,order-edit')->name('orders.lines.update');
Route::delete('orders/{token}/lines/{line}', [PublicOrderController::class, 'dropLine'])->where('token', '[A-Za-z0-9]{40}')->whereNumber('line')->middleware('throttle:20,1,order-edit')->name('orders.lines.drop');
Route::post('orders/{token}/cancel', [PublicOrderController::class, 'cancel'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:10,1,order-cancel')->name('orders.cancel');
Route::get('receipt/{kind}/{token}', [ReceiptController::class, 'show'])->where('kind', 'market|food|game|custom')->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:30,1,receipt')->name('receipt.show');
Route::get('memo/t/{token}', [MemoController::class, 'token'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:30,1,memo-link')->name('memo.token');
// Pre-order (spec ch 21): the same screens for a shop, a bulk buyer and a plain account.
Route::middleware('auth')->group(function () {
    Route::get('preorders', [PreorderController::class, 'index'])->name('preorders.index');
    Route::get('preorders/{preorder}', [PreorderController::class, 'show'])->name('preorders.show');
    Route::post('preorders', [PreorderController::class, 'store'])->middleware('throttle:20,1,preorder')->name('preorders.store');
    Route::post('preorders/{preorder}/cancel', [PreorderController::class, 'cancel'])->name('preorders.cancel');
    Route::post('notices/{notice}/read', [PreorderController::class, 'readNotice'])->name('notices.read');
    Route::get('inbox', [InboxController::class, 'index'])->name('inbox.index');
    Route::post('inbox/read-all', [InboxController::class, 'readAll'])->name('inbox.read-all');
    Route::post('push/subscribe', [PushController::class, 'subscribe'])->middleware('throttle:20,1,push')->name('push.subscribe');
    Route::delete('push/subscribe', [PushController::class, 'unsubscribe'])->middleware('throttle:20,1,push')->name('push.unsubscribe');
    Route::get('wallet', [WalletController::class, 'index'])->name('wallet');
    Route::post('wallet/points', [WalletController::class, 'points'])->middleware('throttle:10,1,reward-points')->name('wallet.points');
    Route::get('market-offers', [MarketOfferController::class, 'index'])->name('market.offers');
    Route::post('market-offers/{offer}/order', [MarketOfferController::class, 'order'])->whereNumber('offer')->middleware('throttle:10,1,offer-order')->name('market.offers.order');
    Route::post('market-offers/{offer}/withdraw', [MarketOfferController::class, 'withdraw'])->whereNumber('offer')->name('market.offers.withdraw');
});

Route::middleware('auth')->group(function () {
    Route::get('my-orders', [PublicOrderController::class, 'index'])->name('orders.index');
    Route::put('my-orders/buy-again', [PublicOrderController::class, 'buyAgain'])->name('orders.buy-again');
    Route::get('my-orders/returns', [PublicOrderController::class, 'returns'])->name('orders.returns');
    Route::post('my-orders/returns', [PublicOrderController::class, 'requestReturn'])->middleware('throttle:10,1,public-return')->name('orders.returns.store');
    Route::delete('addresses/{address}', [CheckoutController::class, 'forgetAddress'])->name('addresses.destroy');
});

// A memo to print or send: staff and admin see any, a shop sees its own (spec ch 22).
Route::middleware('auth')->get('memo/{invoice}', [MemoController::class, 'show'])->name('memo.show');

// The buyer's portal: one set of screens for a shop (spec ch 07) and for a bulk buyer (ch 14), each under its own
// address. The section gate hides each from everyone else; the controller picks prices and kind from the account.
foreach (['paikari', 'wholesale'] as $portal) {
    // The wholesale link has no sign-in step of its own: the section gate treats a guest as the public, so anyone who is not a
    // wholesaler goes quietly to the homepage and never meets a login page that gives the section away (spec ch 05, deep links).
    Route::middleware(array_merge($portal === 'wholesale' ? ['section:wholesale'] : ['auth', 'section:'.$portal], ['two-factor.required']))->prefix($portal)->name($portal.'.')->group(function () use ($portal) {
        Route::get('/', [PaikariController::class, 'index'])->name('index');
        Route::get('search', [PaikariController::class, 'search'])->name('search');
        if ($portal === 'wholesale') {
            // A trade account sells too (spec ch 14): its listings wait for us, we add our commission, buyers pay both.
            Route::get('sell', [SellController::class, 'index'])->name('sell.index');
            Route::get('sell/reports', [SellController::class, 'reports'])->name('sell.reports');
            Route::put('sell/business-name', [SellController::class, 'businessName'])->name('sell.business-name');
            Route::get('sell/statements', [SellController::class, 'statements'])->name('sell.statements');
            Route::post('sell/statements/{statement}/reply', [SellController::class, 'reply'])->name('sell.statements.reply');
            Route::get('sell/orders', [SellController::class, 'orders'])->name('sell.orders');
            Route::post('sell/orders/{order}/accept', [SellController::class, 'accept'])->name('sell.orders.accept');
            Route::put('shop-address', [SellController::class, 'shopAddress'])->name('sell.shop-address');
            Route::post('sell/orders/{order}/collected', [SellController::class, 'collected'])->name('sell.orders.collected');
            Route::post('sell/orders/{order}/ship', [SellController::class, 'ship'])->name('sell.orders.ship');
            Route::post('sell/cases/{case}/answer', [SellController::class, 'answerCase'])->name('sell.cases.answer');
            Route::post('sell/orders/{order}/cancel', [SellController::class, 'cancelOrder'])->name('sell.orders.cancel');
            Route::post('sell', [SellController::class, 'store'])->middleware('throttle:20,1,sell')->name('sell.store');
            Route::post('sell/catalog-request', [SellController::class, 'catalogRequest'])->middleware('throttle:10,1,catalog-request')->name('sell.catalog-request');
            Route::post('sell/{listing}/digital', [SellController::class, 'attachDigital'])->whereNumber('listing')->middleware('throttle:10,1,sell-digital')->name('sell.digital.attach');
            Route::delete('sell/{listing}/digital', [SellController::class, 'removeDigital'])->whereNumber('listing')->name('sell.digital.remove');
            Route::post('sell/{listing}/photos', [SellController::class, 'addPhoto'])->whereNumber('listing')->name('sell.photos.add');
            Route::post('sell/{listing}/video', [SellController::class, 'attachVideo'])->whereNumber('listing')->name('sell.video.attach');
            Route::delete('sell/{listing}/video', [SellController::class, 'removeVideo'])->whereNumber('listing')->name('sell.video.remove');
            Route::delete('sell/photos/{photo}', [SellController::class, 'removePhoto'])->whereNumber('photo')->name('sell.photos.remove');
            Route::put('sell/photos/{photo}/main', [SellController::class, 'mainPhoto'])->whereNumber('photo')->name('sell.photos.main');
            Route::post('sell/{listing}', [SellController::class, 'update'])->whereNumber('listing')->name('sell.update');
            Route::put('sell/{listing}/pause', [SellController::class, 'pause'])->whereNumber('listing')->name('sell.pause');
            Route::delete('sell/{listing}', [SellController::class, 'destroy'])->whereNumber('listing')->name('sell.destroy');
            Route::put('vacation', [SellController::class, 'vacation'])->name('sell.vacation');
            Route::put('sell-token', [SellController::class, 'token'])->name('sell.token');
            Route::put('sell/{listing}/variants', [SellController::class, 'variants'])->whereNumber('listing')->name('sell.variants');
            Route::post('sell/{listing}/color-photo', [SellController::class, 'colorPhoto'])->whereNumber('listing')->name('sell.color-photo');
            Route::get('sell/offers', [SellController::class, 'offers'])->name('sell.offers');
            Route::post('sell/offers/{offer}', [SellController::class, 'answerOffer'])->whereNumber('offer')->name('sell.offers.answer');
            // Services people give and others book (item 613).
            Route::get('sell/services', [SellServicesController::class, 'index'])->name('sell.services');
            Route::post('sell/services', [SellServicesController::class, 'store'])->middleware('throttle:30,1,sell-services')->name('sell.services.store');
            Route::put('sell/services/{offer}', [SellServicesController::class, 'update'])->whereNumber('offer')->middleware('throttle:30,1,sell-services')->name('sell.services.update');
            Route::post('sell/services/{offer}/slots', [SellServicesController::class, 'addSlots'])->whereNumber('offer')->middleware('throttle:30,1,sell-services')->name('sell.services.slots');
            Route::delete('sell/services/slots/{slot}', [SellServicesController::class, 'removeSlot'])->whereNumber('slot')->name('sell.services.slots.remove');
            Route::post('sell/services/bookings/{booking}/confirm', [SellServicesController::class, 'confirm'])->whereNumber('booking')->name('sell.services.confirm');
            Route::post('sell/services/bookings/{booking}/decline', [SellServicesController::class, 'decline'])->whereNumber('booking')->name('sell.services.decline');
            Route::post('sell/services/bookings/{booking}/done', [SellServicesController::class, 'done'])->whereNumber('booking')->name('sell.services.done');
            Route::get('sell/deals', [SellDealsController::class, 'index'])->name('sell.deals');
            Route::post('sell/deals/sale/{listing}', [SellDealsController::class, 'startSale'])->whereNumber('listing')->middleware('throttle:30,1,sell-deals')->name('sell.deals.sale');
            Route::delete('sell/deals/sale/{listing}', [SellDealsController::class, 'endSale'])->whereNumber('listing')->name('sell.deals.sale.end');
            Route::post('sell/deals/bundles', [SellDealsController::class, 'createBundle'])->middleware('throttle:30,1,sell-deals')->name('sell.deals.bundle');
            Route::delete('sell/deals/bundles/{bundle}', [SellDealsController::class, 'removeBundle'])->whereNumber('bundle')->name('sell.deals.bundle.remove');
            Route::get('sell/questions', [SellController::class, 'questions'])->name('sell.questions');
            Route::put('sell/questions/{question}', [SellController::class, 'answerQuestion'])->whereNumber('question')->name('sell.questions.answer');
        }
        Route::post('cart', [PaikariController::class, 'addLine'])->name('cart.add');
        Route::post('offers/{offer}/add', [PaikariController::class, 'addOffer'])->name('offers.add');
        Route::put('cart/{line}', [PaikariController::class, 'setQuantity'])->name('cart.quantity');
        Route::delete('cart/{line}', [PaikariController::class, 'removeLine'])->name('cart.remove');
        Route::put('reminders', [PaikariController::class, 'reminders'])->name('reminders');
        Route::post('short-list', [PaikariController::class, 'remember'])->name('short.add');
        Route::delete('short-list/{item}', [PaikariController::class, 'forget'])->name('short.remove');
        Route::post('short-list/{item}/order', [PaikariController::class, 'orderFromShortList'])->name('short.order');
        Route::post('order', [PaikariController::class, 'place'])->name('order.place');
        Route::post('notices/{notice}/read', [PaikariController::class, 'readNotice'])->name('notices.read');
        Route::post('prescriptions', [PaikariController::class, 'prescription'])->name('prescriptions.store');
        Route::post('orders/{invoice}/add', [PaikariController::class, 'addMore'])->name('orders.add');
        Route::post('orders/{invoice}/note', [PaikariController::class, 'addNote'])->name('orders.note');
        Route::get('returns', [PaikariController::class, 'returns'])->name('returns.index');
        Route::post('returns', [PaikariController::class, 'requestReturn'])->name('returns.store');
        Route::get('orders/{invoice}', [PaikariController::class, 'show'])->name('orders.show');
        Route::post('orders/{invoice}/cancel', [PaikariController::class, 'cancel'])->name('orders.cancel');
        Route::post('orders/{invoice}/received', [PaikariController::class, 'received'])->name('orders.received');
        Route::post('orders/{invoice}/not-received', [PaikariController::class, 'notReceived'])->name('orders.not-received');

        if ($portal === 'wholesale') {
            Route::get('account', [WholesaleController::class, 'account'])->name('account');
            Route::post('account/branding', [WholesaleController::class, 'branding'])->middleware('throttle:20,1,branding')->name('account.branding');
            Route::get('account/logo', [WholesaleController::class, 'logo'])->name('account.logo');
            // Bulk upload and custom quotation (spec ch 14): a bulk buyer's own tools.
            Route::post('bulk', [PaikariController::class, 'bulk'])->middleware('throttle:10,1,bulk')->name('bulk');
            Route::get('quotes', [QuoteController::class, 'index'])->name('quotes');
            Route::post('quotes', [QuoteController::class, 'store'])->middleware('throttle:20,1,quotes')->name('quotes.store');
            Route::post('quotes/{quote}/respond', [QuoteController::class, 'respond'])->name('quotes.respond');
            Route::post('quotes/{quote}/cancel', [QuoteController::class, 'cancel'])->name('quotes.cancel');
        }
    });
}

// The MPO's own screen (spec ch 10): their companies, chosen products and listings only.
Route::middleware(['auth', 'section:mpo'])->prefix('mpo')->name('mpo.')->group(function () {
    Route::get('/', [MpoPortalController::class, 'index'])->name('index');
    Route::get('companies/{company}/medicines', [MpoPortalController::class, 'medicines'])->name('medicines');
    Route::post('products', [MpoPortalController::class, 'choose'])->name('products');
    Route::post('listings', [MpoPortalController::class, 'submit'])->name('listings.store');
    Route::delete('listings/{listing}', [MpoPortalController::class, 'withdraw'])->name('listings.withdraw');
    Route::post('bids/{bid}/accept', [MpoPortalController::class, 'accept'])->name('bids.accept');
    Route::post('listings/{listing}/raise', [MpoPortalController::class, 'raise'])->name('listings.raise');
});

// Food from local restaurants (spec ch 17): open to everyone, no account needed. The restaurant's own screen is only for its owner.
Route::get('food', [FoodController::class, 'index'])->name('food.index');
Route::get('food/r/{restaurant}', [FoodController::class, 'show'])->whereNumber('restaurant')->name('food.show');
Route::get('food/r/{restaurant}/photo', [FoodController::class, 'restaurantPhoto'])->whereNumber('restaurant')->name('food.photo');
Route::get('food/items/{item}/photo', [FoodController::class, 'itemPhoto'])->whereNumber('item')->name('food.item-photo');
Route::post('food/r/{restaurant}/order', [FoodController::class, 'store'])->whereNumber('restaurant')->middleware('throttle:10,1,food-order')->name('food.order.store');
Route::get('food/orders/{token}', [FoodController::class, 'order'])->where('token', '[A-Za-z0-9]{40}')->name('food.order');
Route::post('food/orders/{token}/cancel', [FoodController::class, 'cancel'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:10,1,food-cancel')->name('food.order.cancel');
Route::post('food/orders/{token}/complain', [FoodController::class, 'complain'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:10,1,food-complain')->name('food.order.complain');
Route::get('food/orders/{token}/photo', [FoodController::class, 'complaintPhoto'])->where('token', '[A-Za-z0-9]{40}')->name('food.order.photo');
Route::get('food-orders', [FoodController::class, 'mine'])->middleware('auth')->name('food.orders');
Route::post('food/favorites', [FoodController::class, 'saveFavorite'])->middleware(['auth', 'throttle:30,1,food-favorite'])->name('food.favorites.store');
Route::delete('food/favorites/{favorite}', [FoodController::class, 'forgetFavorite'])->middleware('auth')->whereNumber('favorite')->name('food.favorites.destroy');

// Game top-up (spec ch 18): open to everyone; online payment only.
Route::get('gaming', [GamingController::class, 'index'])->name('gaming.index');
Route::get('gaming/{game:slug}/cover', [GamingController::class, 'cover'])->name('gaming.cover');
Route::get('gaming/orders/{token}', [GamingController::class, 'order'])->where('token', '[A-Za-z0-9]{40}')->name('gaming.order');
Route::post('gaming/orders/{token}/claim', [GamingController::class, 'claim'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:10,1,gaming-claim')->name('gaming.claim');
Route::post('gaming/orders/{token}/cancel', [GamingController::class, 'cancel'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:10,1,gaming-cancel')->name('gaming.cancel');
Route::get('gaming-orders', [GamingController::class, 'mine'])->middleware('auth')->name('gaming.orders');
Route::post('gaming/packages/{package}/order', [GamingController::class, 'store'])->whereNumber('package')->middleware('throttle:10,1,gaming-order')->name('gaming.store');
Route::post('gaming/{game:slug}/verify', [GamingController::class, 'verify'])->middleware('throttle:30,1,gaming-verify')->name('gaming.verify');
Route::get('gaming/{game:slug}', [GamingController::class, 'show'])->name('gaming.show');

Route::middleware('auth')->prefix('food/vendor')->name('food.vendor.')->group(function () {
    Route::get('/', [FoodVendorController::class, 'index'])->name('index');
    Route::get('locate', [FoodVendorController::class, 'locate'])->middleware('throttle:15,1,food-locate')->name('locate');
    Route::get('custom', [FoodVendorController::class, 'custom'])->name('custom');
    Route::post('custom/tick', [FoodVendorController::class, 'tick'])->name('custom.tick');
    Route::delete('custom/ingredients/{ingredient}', [FoodVendorController::class, 'untick'])->whereNumber('ingredient')->name('custom.untick');
    Route::put('custom/ri/{ri}/available', [FoodVendorController::class, 'ingredientAvailable'])->whereNumber('ri')->name('custom.ri.available');
    Route::put('custom/ri/{ri}/sold-out', [FoodVendorController::class, 'ingredientSoldOut'])->whereNumber('ri')->name('custom.ri.sold-out');
    Route::post('custom/bases', [FoodVendorController::class, 'saveBase'])->name('custom.bases.store');
    Route::post('custom/bases/{base}', [FoodVendorController::class, 'saveBase'])->whereNumber('base')->name('custom.bases.update');
    Route::put('custom/bases/{base}/available', [FoodVendorController::class, 'baseAvailable'])->whereNumber('base')->name('custom.bases.available');
    Route::put('custom/bases/{base}/sold-out', [FoodVendorController::class, 'baseSoldOut'])->whereNumber('base')->name('custom.bases.sold-out');
    Route::delete('custom/bases/{base}', [FoodVendorController::class, 'removeBase'])->whereNumber('base')->name('custom.bases.destroy');
    Route::post('custom/rules', [FoodVendorController::class, 'addRule'])->name('custom.rules.store');
    Route::delete('custom/rules/{rule}', [FoodVendorController::class, 'removeRule'])->whereNumber('rule')->name('custom.rules.destroy');
    Route::put('custom/limits', [FoodVendorController::class, 'limits'])->name('custom.limits');
    Route::post('custom/requests', [FoodVendorController::class, 'requestIngredient'])->middleware('throttle:20,1,food-ingredient')->name('custom.requests');
    Route::put('open', [FoodVendorController::class, 'setOpen'])->name('open');
    Route::post('settings', [FoodVendorController::class, 'update'])->name('settings');
    Route::post('items', [FoodVendorController::class, 'storeItem'])->name('items.store');
    Route::post('items/{item}', [FoodVendorController::class, 'updateItem'])->whereNumber('item')->name('items.update');
    Route::put('items/{item}/available', [FoodVendorController::class, 'available'])->whereNumber('item')->name('items.available');
    Route::put('items/{item}/sold-out', [FoodVendorController::class, 'soldOut'])->whereNumber('item')->name('items.sold-out');
    Route::delete('items/{item}', [FoodVendorController::class, 'destroyItem'])->whereNumber('item')->name('items.destroy');
    Route::post('orders/{order}/confirm', [FoodVendorController::class, 'confirm'])->whereNumber('order')->name('orders.confirm');
    Route::post('orders/{order}/advance', [FoodVendorController::class, 'advance'])->whereNumber('order')->name('orders.advance');
    Route::post('orders/{order}/cancel', [FoodVendorController::class, 'cancel'])->whereNumber('order')->name('orders.cancel');
    Route::post('orders/{order}/reply', [FoodVendorController::class, 'reply'])->whereNumber('order')->name('orders.reply');
    Route::get('orders/{order}/photo', [FoodVendorController::class, 'complaintPhoto'])->whereNumber('order')->name('orders.photo');
    Route::get('orders/{order}/print/{kind}', [FoodVendorController::class, 'print'])->whereNumber('order')->name('orders.print');
    Route::post('orders/{order}/{step}', [FoodVendorController::class, 'step'])->whereNumber('order')->name('orders.step');
});

// A craftsman's own screen (spec ch 16.4): only the jobs they are on, never the buyer's price, our profit, the buyer's phone or stock.
Route::middleware(['auth', 'section:craftsman'])->prefix('craftsman')->name('craftsman.')->group(function () {
    Route::get('/', [CraftsmanController::class, 'index'])->name('index');
    Route::get('jobs/{order}', [CraftsmanController::class, 'show'])->whereNumber('order')->name('jobs.show');
    Route::get('jobs/{order}/photo', [CraftsmanController::class, 'jobPhoto'])->whereNumber('order')->name('jobs.photo');
    Route::get('progress/{entry}/photo', [CraftsmanController::class, 'progressPhoto'])->whereNumber('entry')->name('progress.photo');
    Route::post('jobs/{order}/wage', [CraftsmanController::class, 'propose'])->whereNumber('order')->name('jobs.wage');
    Route::post('jobs/{order}/progress', [CraftsmanController::class, 'record'])->whereNumber('order')->middleware('throttle:60,1,craftsman-progress')->name('jobs.progress');
    Route::post('jobs/{order}/shift', [CraftsmanController::class, 'shift'])->whereNumber('order')->name('jobs.shift');
    Route::post('jobs/{order}/share', [CraftsmanController::class, 'share'])->whereNumber('order')->name('jobs.share');
    Route::post('jobs/{order}/waiting', [CraftsmanController::class, 'waiting'])->whereNumber('order')->name('jobs.waiting');
    Route::post('jobs/{order}/chat', [CraftsmanController::class, 'chatSend'])->whereNumber('order')->middleware('throttle:60,1,craftsman-chat')->name('jobs.chat');
    Route::get('jobs/{order}/chat/{message}/file', [CraftsmanController::class, 'chatFile'])->whereNumber('order')->whereNumber('message')->name('jobs.chat.file');
});

// A buyer orders from an "Anonymous N" offer (spec ch 10).
Route::middleware('auth')->post('mpo-offers/{listing}/bid', [MpoOfferController::class, 'bid'])->middleware('throttle:20,1,mpo-bid')->name('mpo-offers.bid');
Route::middleware('auth')->delete('mpo-bids/{bid}', [MpoOfferController::class, 'withdraw'])->name('mpo-bids.withdraw');
Route::middleware('auth')->post('mpo-offers/{listing}/order', [MpoOfferController::class, 'order'])->middleware('throttle:20,1,mpo-order')->name('mpo-offers.order');

// Apply for a business account (spec ch 3, 14, 25): the same neutral page for any signed-in visitor.
Route::middleware('auth')->group(function () {
    Route::get('business', [BusinessApplicationController::class, 'show'])->name('business.show');
    Route::post('business', [BusinessApplicationController::class, 'store'])->middleware('throttle:5,1,business-apply')->name('business.store');
});

// Admin panel (spec ch 25). Section gate keeps everyone else out silently; the policy
// gate then limits customer, tier and pricing controls to the admin account.
Route::middleware(['auth', 'section:admin_panel', 'two-factor.required'])->prefix('admin')->name('admin.')->group(function () {
    Route::post('users/{user}/two-factor/reset', [TwoFactorResetController::class, 'reset'])->whereNumber('user')->name('users.two-factor.reset');
    // Bulk texts (spec ch 27): one message to many, a test to your own phone first.
    // Affiliates (spec ch 27): the owner opens them, sets their share and pays what they earned.
    Route::get('affiliates', [AffiliateController::class, 'index'])->name('affiliates.index');
    Route::post('affiliates', [AffiliateController::class, 'store'])->middleware('throttle:30,1,affiliate-store')->name('affiliates.store');
    Route::put('affiliates/{affiliate}', [AffiliateController::class, 'update'])->whereNumber('affiliate')->name('affiliates.update');
    Route::post('affiliates/{affiliate}/pay', [AffiliateController::class, 'pay'])->whereNumber('affiliate')->middleware('throttle:20,1,affiliate-pay')->name('affiliates.pay');
    Route::get('sms', [SmsController::class, 'index'])->name('sms.index');
    Route::get('sms/audience', [SmsController::class, 'audience'])->middleware('throttle:60,1,sms-audience')->name('sms.audience');
    Route::post('sms', [SmsController::class, 'store'])->middleware('throttle:20,1,sms-store')->name('sms.store');
    Route::get('sms/{campaign}', [SmsController::class, 'show'])->whereNumber('campaign')->name('sms.show');
    Route::post('sms/{campaign}/test', [SmsController::class, 'test'])->whereNumber('campaign')->middleware('throttle:10,1,sms-test')->name('sms.test');
    Route::post('sms/{campaign}/start', [SmsController::class, 'start'])->whereNumber('campaign')->middleware('throttle:10,1,sms-start')->name('sms.start');
    Route::post('sms/{campaign}/cancel', [SmsController::class, 'cancel'])->whereNumber('campaign')->name('sms.cancel');
    Route::get('customers/import', [CustomerImportController::class, 'index'])->name('customers.import');
    Route::get('customers/import/template', [CustomerImportController::class, 'template'])->name('customers.import.template');
    Route::post('customers/import/preview', [CustomerImportController::class, 'preview'])->middleware('throttle:20,1,customer-import')->name('customers.import.preview');
    Route::post('customers/import/{token}', [CustomerImportController::class, 'commit'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:10,1,customer-import')->name('customers.import.commit');
    Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::get('customers/{user}', [CustomerController::class, 'show'])->name('customers.show');
    Route::patch('customers/{user}/tier', [CustomerController::class, 'updateTier'])->name('customers.tier');
    Route::put('customers/{user}/monthly-total', [CustomerController::class, 'monthlyTotal'])->name('customers.monthly-total');
    Route::put('customers/{user}/free-delivery', [CustomerController::class, 'freeDelivery'])->name('customers.free-delivery');
    Route::post('customers/{user}/suspend', [CustomerController::class, 'suspend'])->middleware('password.confirm')->name('customers.suspend');
    Route::delete('customers/{user}/suspend', [CustomerController::class, 'reactivate'])->middleware('password.confirm')->name('customers.reactivate');
    Route::put('customers/{user}/rates', [CustomerController::class, 'saveRate'])->middleware('password.confirm')->name('customers.rates.save');
    Route::delete('customers/{user}/rates/{rate}', [CustomerController::class, 'deleteRate'])->middleware('password.confirm')->name('customers.rates.delete');

    // Pre-order (spec ch 21): answer, edit the draft memo, receive, extend, cancel; the buying list.
    Route::get('preorders', [AdminPreorderController::class, 'index'])->name('preorders.index');
    Route::get('preorders/companies', [AdminPreorderController::class, 'companies'])->name('preorders.companies');
    Route::post('preorders/share', [AdminPreorderController::class, 'share'])->name('preorders.share');
    Route::post('preorders/availability', [AdminPreorderController::class, 'availability'])->name('preorders.availability');
    Route::post('preorders/{preorder}/accept', [AdminPreorderController::class, 'accept'])->name('preorders.accept');
    Route::post('preorders/{preorder}/decline', [AdminPreorderController::class, 'decline'])->name('preorders.decline');
    Route::post('preorders/{preorder}/cancel', [AdminPreorderController::class, 'cancel'])->name('preorders.cancel');
    Route::post('preorders/{preorder}/extend', [AdminPreorderController::class, 'extend'])->name('preorders.extend');
    Route::post('preorders/{preorder}/advance', [AdminPreorderController::class, 'advance'])->name('preorders.advance');
    Route::post('preorders/{preorder}/receive', [AdminPreorderController::class, 'receive'])->name('preorders.receive');
    Route::put('preorders/lines/{line}', [AdminPreorderController::class, 'editLine'])->name('preorders.lines.update');

    // The controlled-medicine register (spec ch 27): written at the counter, read and printed by the admin and sub-admin.
    Route::put('controlled/{line}', [ControlledController::class, 'store'])->name('controlled.store');
    Route::get('controlled', [ControlledController::class, 'index'])->name('controlled.index');
    Route::get('controlled/print', [ControlledController::class, 'print'])->name('controlled.print');
    Route::get('controlled/export', [ControlledController::class, 'export'])->name('controlled.export');

    // Business account applications (spec ch 25): read the licence, then open the right account or say why not.
    Route::get('applications', [ApplicationController::class, 'index'])->name('applications.index');
    Route::get('applications/{application}/file/{which}', [ApplicationController::class, 'file'])->where('which', 'first|second')->name('applications.file');
    Route::post('applications/{application}/approve', [ApplicationController::class, 'approve'])->name('applications.approve');
    Route::post('applications/{application}/reject', [ApplicationController::class, 'reject'])->name('applications.reject');
    Route::put('customers/{user}/trade', [CustomerController::class, 'trade'])->name('customers.trade');

    Route::get('requests', [AdminProductRequestController::class, 'index'])->name('requests.index');
    Route::get('requests/{productRequest}/photo', [AdminProductRequestController::class, 'photo'])->whereNumber('productRequest')->name('requests.photo');

    // Stock (spec ch 06): admin only, purchase prices never leave this gate.
    Route::get('stock', [StockController::class, 'index'])->name('stock.index');
    Route::get('stock/receive', [StockController::class, 'create'])->name('stock.create');
    Route::post('stock/receive', [StockController::class, 'store'])->name('stock.store');
    Route::get('stock/quote', [StockController::class, 'quote'])->name('stock.quote');
    Route::get('stock/companies', [StockController::class, 'companies'])->name('stock.companies');
    Route::get('stock/companies/{company}/medicines', [StockController::class, 'companyMedicines'])->whereNumber('company')->name('stock.companies.medicines');
    Route::put('stock/draft', [StockController::class, 'saveDraft'])->name('stock.draft');
    Route::delete('stock/draft', [StockController::class, 'clearDraft'])->name('stock.draft.clear');
    Route::get('stock/mismatches', [MismatchController::class, 'index'])->name('stock.mismatches');
    Route::post('stock/mismatches', [MismatchController::class, 'store'])->name('stock.mismatches.store');
    Route::post('stock/mismatches/{mismatch}', [MismatchController::class, 'resolve'])->name('stock.mismatches.resolve');
    Route::put('stock/lots/{lot}/cost', [StockController::class, 'lotCost'])->name('stock.lots.cost');
    Route::get('stock/lots/{lot}/cost', [StockController::class, 'lotCostPreview'])->name('stock.lots.cost-preview');
    Route::get('stock/medicines/{medicine}/edit', [AdminMedicineController::class, 'edit'])->name('stock.medicine.edit');
    Route::put('stock/medicines/{medicine}', [AdminMedicineController::class, 'update'])->name('stock.medicine.update');
    Route::put('stock/medicines/{medicine}/steps', [AdminMedicineController::class, 'steps'])->name('stock.medicine.steps');
    Route::get('stock/inactive', [AdminMedicineController::class, 'inactive'])->name('stock.inactive');
    Route::put('stock/medicines/{medicine}/active', [AdminMedicineController::class, 'active'])->name('stock.medicine.active');
    Route::post('stock/medicines/{medicine}/barcodes', [AdminMedicineController::class, 'addBarcode'])->name('stock.medicine.barcodes.add');
    Route::delete('stock/medicines/{medicine}/barcodes/{barcode}', [AdminMedicineController::class, 'removeBarcode'])->name('stock.medicine.barcodes.remove');
    Route::post('stock/master-sync', [StockController::class, 'masterSync'])->name('stock.master-sync');
    Route::get('stock/sources', [StockSourceController::class, 'index'])->name('stock.sources');
    Route::post('stock/sources', [StockSourceController::class, 'store'])->name('stock.sources.store');
    Route::put('stock/sources/{source}', [StockSourceController::class, 'update'])->name('stock.sources.update');
    Route::put('stock/rates/company/{company}', [StockSourceController::class, 'companyRate'])->middleware('password.confirm')->name('stock.rates.company');
    Route::put('stock/rates/medicine/{medicine}', [StockSourceController::class, 'medicineRate'])->middleware('password.confirm')->name('stock.rates.medicine');
    Route::get('stock/expiry', [ExpiryController::class, 'index'])->name('stock.expiry');
    Route::get('stock/expiry/return-list', [ExpiryController::class, 'returnList'])->name('stock.expiry.return-list');
    Route::post('stock/expiry/checked', [ExpiryController::class, 'checked'])->name('stock.expiry.checked');
    Route::post('stock/expiry/settings', [ExpiryController::class, 'settings'])->name('stock.expiry.settings');
    Route::post('stock/lots/{lot}/expire', [ExpiryController::class, 'expire'])->name('stock.lots.expire');
    Route::put('stock/lots/{lot}/return-status', [ExpiryController::class, 'returnStatus'])->name('stock.lots.return-status');
    Route::get('stock/counts', [StockCountController::class, 'index'])->name('stock.counts');
    Route::post('stock/counts', [StockCountController::class, 'store'])->name('stock.counts.store');
    Route::get('stock/counts/{count}', [StockCountController::class, 'show'])->name('stock.counts.show');
    Route::post('stock/counts/{count}/record', [StockCountController::class, 'record'])->name('stock.counts.record');
    Route::post('stock/counts/{count}/scan', [StockCountController::class, 'scan'])->name('stock.counts.scan');
    Route::post('stock/counts/{count}/pause', [StockCountController::class, 'pause'])->name('stock.counts.pause');
    Route::post('stock/counts/{count}/cancel', [StockCountController::class, 'cancel'])->name('stock.counts.cancel');
    Route::post('stock/counts/{count}/approve', [StockCountController::class, 'approve'])->name('stock.counts.approve');
    Route::post('stock/barcodes', [StockCountController::class, 'linkBarcode'])->name('stock.barcodes.link');
    Route::get('stock/barcodes/lookup', [StockCountController::class, 'lookup'])->name('stock.barcodes.lookup');
    Route::get('stock/reports', [StockReportController::class, 'index'])->name('stock.reports');
    Route::post('stock/expenses', [StockReportController::class, 'storeExpense'])->name('stock.expenses.store');
    Route::delete('stock/expenses/{expense}', [StockReportController::class, 'destroyExpense'])->name('stock.expenses.destroy');
    Route::put('stock/medicines/{medicine}/reorder-level', [StockReportController::class, 'reorderLevel'])->name('stock.reorder-level');
    Route::get('stock/medicines/{medicine}', [StockController::class, 'show'])->name('stock.show');
    Route::post('stock/medicines/{medicine}/correct', [StockController::class, 'correct'])->middleware('password.confirm')->name('stock.correct');

    // Every order and memo with filters, and taking a payment on a memo left due (spec ch 25).
    Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::post('invoices/{invoice}/payment', [InvoiceController::class, 'pay'])->name('invoices.pay');

    // The credit ledger (baki khata, spec ch 28 note 12): customers who owe us, and what we owe each company with its due day.
    Route::get('ledger', [LedgerController::class, 'index'])->name('ledger.index');
    Route::get('ledger/{user}', [LedgerController::class, 'show'])->whereNumber('user')->name('ledger.show');
    Route::post('ledger/{user}/payment', [LedgerController::class, 'pay'])->whereNumber('user')->name('ledger.pay');
    Route::get('payables', [PayablesController::class, 'index'])->name('payables.index');
    Route::get('payables/{source}', [PayablesController::class, 'show'])->whereNumber('source')->name('payables.show');
    Route::post('payables/{source}/payment', [PayablesController::class, 'pay'])->whereNumber('source')->name('payables.pay');

    // Online orders from shops (spec 4.4): staff check each line.
    Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('orders/{invoice}', [OrderController::class, 'show'])->name('orders.show');
    Route::get('orders/{invoice}/attachment/{kind}', [OrderController::class, 'attachment'])->where('kind', 'voice|photo')->name('orders.attachment');
    Route::post('orders/{invoice}/lines/{line}/verify', [OrderController::class, 'verify'])->name('orders.verify');
    Route::put('orders/{invoice}/lines/{line}/price', [OrderController::class, 'price'])->name('orders.price');
    Route::post('orders/{invoice}/cancel-decision', [OrderController::class, 'cancelDecision'])->name('orders.cancel-decision');
    Route::post('orders/{invoice}/not-collected', [OrderController::class, 'notCollected'])->name('orders.not-collected');
    Route::put('orders/{invoice}/delivery', [OrderController::class, 'delivery'])->name('orders.delivery');
    Route::post('orders/{invoice}/complete', [OrderController::class, 'complete'])->name('orders.complete');
    Route::post('orders/{invoice}/working', [OrderController::class, 'working'])->name('orders.working');
    Route::post('orders/{invoice}/delivery-advance', [OrderController::class, 'deliveryAdvance'])->name('orders.delivery-advance');
    Route::post('orders/{invoice}/send', [OrderController::class, 'send'])->name('orders.send');
    Route::post('orders/{invoice}/not-received', [OrderController::class, 'settleNotReceived'])->name('orders.not-received');

    // The MPO sector (spec ch 10): only the owner opens accounts, sets territory and companies, and decides markets and prices.
    Route::get('mpo', [MpoController::class, 'index'])->name('mpo.index');
    Route::post('mpo', [MpoController::class, 'store'])->name('mpo.store');
    Route::get('mpo/listings', [MpoController::class, 'listings'])->name('mpo.listings');
    Route::post('mpo/{user}/photo', [MpoController::class, 'photo'])->whereNumber('user')->middleware('throttle:20,1,mpo-photo')->name('mpo.photo');
    Route::get('mpo/{user}/photo', [MpoController::class, 'showPhoto'])->whereNumber('user')->name('mpo.photo.show');
    Route::post('mpo/listings/{listing}/approve', [MpoController::class, 'approve'])->name('mpo.listings.approve');
    Route::post('mpo/listings/{listing}/reject', [MpoController::class, 'reject'])->name('mpo.listings.reject');
    Route::get('mpo/{user}', [MpoController::class, 'show'])->name('mpo.show');
    Route::put('mpo/{user}', [MpoController::class, 'update'])->name('mpo.update');
    Route::put('mpo/{user}/companies', [MpoController::class, 'companies'])->name('mpo.companies');
    Route::post('mpo/{user}/close', [MpoController::class, 'close'])->name('mpo.close');
    Route::post('preorders/{preorder}/take-from-mpo', [AdminPreorderController::class, 'takeFromMpo'])->name('preorders.take-from-mpo');

    // Couriers with charges by zone and weight, and weights on medicines (spec ch 20).
    Route::get('market', [AdminMarketController::class, 'index'])->name('market.index');
    Route::post('market/prohibited', [AdminMarketController::class, 'saveProhibited'])->name('market.prohibited');
    Route::get('market/commissions', [AdminMarketController::class, 'commissions'])->name('market.commissions');
    Route::get('market/statements', [AdminMarketController::class, 'statements'])->name('market.statements');
    Route::get('custom', [AdminCustomOrderController::class, 'index'])->name('custom.index');
    Route::post('custom/materials', [AdminCustomOrderController::class, 'storeMaterial'])->name('custom.materials.store');
    Route::put('custom/materials/{material}', [AdminCustomOrderController::class, 'updateMaterial'])->name('custom.materials.update');
    Route::get('gaming', [AdminGamingController::class, 'index'])->name('gaming.index');
    Route::post('gaming/games', [AdminGamingController::class, 'storeGame'])->name('gaming.games.store');
    Route::put('gaming/games/{game}', [AdminGamingController::class, 'updateGame'])->whereNumber('game')->name('gaming.games.update');
    Route::post('gaming/games/{game}/cover', [AdminGamingController::class, 'cover'])->whereNumber('game')->middleware('throttle:30,1,game-cover')->name('gaming.games.cover');
    Route::delete('gaming/games/{game}/cover', [AdminGamingController::class, 'removeCover'])->whereNumber('game')->name('gaming.games.cover.remove');
    Route::post('gaming/games/{game}/packages', [AdminGamingController::class, 'storePackage'])->whereNumber('game')->name('gaming.packages.store');
    Route::put('gaming/packages/{package}', [AdminGamingController::class, 'updatePackage'])->whereNumber('package')->name('gaming.packages.update');
    Route::post('gaming/orders/{order}/{action}', [AdminGamingController::class, 'act'])->whereNumber('order')->name('gaming.orders.act');
    Route::get('food', [AdminFoodController::class, 'index'])->name('food.index');
    Route::post('food', [AdminFoodController::class, 'store'])->name('food.store');
    Route::put('food/{restaurant}/commission', [AdminFoodController::class, 'commission'])->name('food.commission');
    Route::put('food/{restaurant}/suspend', [AdminFoodController::class, 'suspend'])->name('food.suspend');
    Route::get('food-ingredients', [AdminFoodController::class, 'ingredients'])->name('food.ingredients');
    Route::post('food-ingredients', [AdminFoodController::class, 'storeIngredient'])->name('food.ingredients.store');
    Route::put('food-ingredients/{ingredient}', [AdminFoodController::class, 'updateIngredient'])->whereNumber('ingredient')->name('food.ingredients.update');
    Route::post('food-ingredient-requests/{ingredientRequest}', [AdminFoodController::class, 'decideRequest'])->whereNumber('ingredientRequest')->name('food.ingredients.decide');
    Route::get('food/orders/{order}/photo', [AdminFoodController::class, 'complaintPhoto'])->whereNumber('order')->name('food.photo');
    Route::get('chats', [CustomChatController::class, 'index'])->name('chats.index');
    Route::get('chats/file/{message}', [CustomChatController::class, 'file'])->whereNumber('message')->name('chats.file');
    Route::get('chats/{order}', [CustomChatController::class, 'show'])->whereNumber('order')->name('chats.show');
    Route::post('chats/{order}', [CustomChatController::class, 'send'])->whereNumber('order')->name('chats.send');
    Route::post('chats/{order}/offer', [CustomChatController::class, 'offer'])->whereNumber('order')->name('chats.offer');
    Route::get('custom/{order}/photo', [AdminCustomOrderController::class, 'photo'])->name('custom.photo');
    Route::put('custom/{order}/price', [AdminCustomOrderController::class, 'price'])->name('custom.price');
    Route::post('custom/{order}/advance', [AdminCustomOrderController::class, 'advance'])->name('custom.advance');
    Route::post('custom/{order}/cancel', [AdminCustomOrderController::class, 'cancel'])->name('custom.cancel');
    Route::post('custom/{order}/complaint', [AdminCustomOrderController::class, 'complaint'])->name('custom.complaint');
    Route::post('custom/{order}/wage-ask', [AdminCustomOrderController::class, 'askWage'])->name('custom.wage-ask');
    Route::post('custom/{order}/wages', [AdminCustomOrderController::class, 'wages'])->name('custom.wages');
    Route::post('custom/{order}/assign', [AdminCustomOrderController::class, 'assign'])->name('custom.assign');
    Route::post('custom/{order}/extend', [AdminCustomOrderController::class, 'extend'])->name('custom.extend');
    Route::post('custom/{order}/give-advance', [AdminCustomOrderController::class, 'giveAdvance'])->name('custom.give-advance');
    Route::post('custom/{order}/materials-arrived', [AdminCustomOrderController::class, 'materialsArrived'])->name('custom.materials-arrived');
    Route::put('custom/progress/{entry}', [AdminCustomOrderController::class, 'correctProgress'])->whereNumber('entry')->name('custom.progress.correct');
    Route::get('custom/progress/{entry}/photo', [AdminCustomOrderController::class, 'progressPhoto'])->whereNumber('entry')->name('custom.progress.photo');
    Route::get('craftsmen', [AdminCraftsmanController::class, 'index'])->name('craftsmen.index');
    Route::post('craftsmen', [AdminCraftsmanController::class, 'store'])->name('craftsmen.store');
    Route::post('craftsmen/{user}/pay', [AdminCraftsmanController::class, 'pay'])->name('craftsmen.pay');
    Route::post('custom/{order}/{step}', [AdminCustomOrderController::class, 'step'])->name('custom.step');
    // Shop fronts: the sections of each shop's front page, built and worded by the owner.
    Route::get('storefront', [AdminStorefrontController::class, 'index'])->name('storefront.index');
    Route::post('storefront/{sector}/defaults', [AdminStorefrontController::class, 'seed'])->whereNumber('sector')->name('storefront.seed');
    Route::post('storefront/{sector}/blocks', [AdminStorefrontController::class, 'store'])->whereNumber('sector')->name('storefront.store');
    Route::post('storefront/pages/{page}/defaults', [AdminStorefrontController::class, 'seedPage'])->whereIn('page', ['medicines', 'food', 'gaming', 'market'])->name('storefront.seed-page');
    Route::post('storefront/pages/{page}/blocks', [AdminStorefrontController::class, 'storePage'])->whereIn('page', ['medicines', 'food', 'gaming', 'market'])->name('storefront.store-page');
    Route::put('storefront/blocks/{block}', [AdminStorefrontController::class, 'update'])->whereNumber('block')->name('storefront.update');
    Route::post('storefront/blocks/{block}/move', [AdminStorefrontController::class, 'move'])->whereNumber('block')->name('storefront.move');
    Route::delete('storefront/blocks/{block}', [AdminStorefrontController::class, 'destroy'])->whereNumber('block')->name('storefront.destroy');
    Route::post('storefront/blocks/{block}/items/{index}/image', [AdminStorefrontController::class, 'itemImage'])->whereNumber(['block', 'index'])->middleware('throttle:30,1,storefront-item-image')->name('storefront.item-image');
    Route::delete('storefront/blocks/{block}/items/{index}/image', [AdminStorefrontController::class, 'removeItemImage'])->whereNumber(['block', 'index'])->name('storefront.item-image.remove');
    Route::post('storefront/blocks/{block}/image', [AdminStorefrontController::class, 'image'])->whereNumber('block')->middleware('throttle:30,1,storefront-image')->name('storefront.image');
    Route::delete('storefront/blocks/{block}/image', [AdminStorefrontController::class, 'removeImage'])->whereNumber('block')->name('storefront.image.remove');
    Route::get('site', [AdminSiteController::class, 'index'])->name('site.index');
    Route::get('site/builder', [AdminSiteController::class, 'builder'])->name('site.builder');
    Route::put('site/blocks', [AdminSiteController::class, 'saveBlocks'])->name('site.blocks');
    Route::post('site/block-image', [AdminSiteController::class, 'blockImage'])->middleware('throttle:30,1,site-block-image')->name('site.block-image');
    Route::put('site/{group}', [AdminSiteController::class, 'save'])->whereIn('group', ['brand', 'contact', 'social', 'footer', 'home', 'announcement', 'seo'])->name('site.save');
    Route::post('site/image/{which}', [AdminSiteController::class, 'image'])->whereIn('which', ['logo', 'favicon'])->name('site.image');
    Route::delete('site/image/{which}', [AdminSiteController::class, 'removeImage'])->whereIn('which', ['logo', 'favicon'])->name('site.image.remove');
    Route::get('rx-market', [AdminRxInterestController::class, 'index'])->name('rx-market.index');
    Route::get('services', [AdminServiceOfferController::class, 'index'])->name('services.index');
    Route::put('services/{offer}/hide', [AdminServiceOfferController::class, 'hide'])->whereNumber('offer')->name('services.hide');
    Route::get('pharmatrack', [AdminPharmaTrackController::class, 'index'])->name('pharmatrack.index');
    Route::post('pharmatrack/token', [AdminPharmaTrackController::class, 'newToken'])->middleware(['password.confirm', 'throttle:5,1,pharmatrack-token'])->name('pharmatrack.token');
    Route::post('pharmatrack/retry', [AdminPharmaTrackController::class, 'retry'])->middleware('throttle:5,1,pharmatrack-retry')->name('pharmatrack.retry');
    Route::get('connections', [AdminIntegrationController::class, 'index'])->name('connections.index');
    Route::put('connections/{key}', [AdminIntegrationController::class, 'save'])->where('key', '[a-z]+:[a-z0-9_]+')->name('connections.save');
    Route::post('connections/{key}/test', [AdminIntegrationController::class, 'test'])->where('key', '[a-z]+:[a-z0-9_]+')->middleware('throttle:10,1,connection-test')->name('connections.test');
    Route::post('orders/{invoice}/book-courier', [CourierBookingController::class, 'book'])->whereNumber('invoice')->middleware('throttle:20,1,courier-book')->name('orders.book-courier');
    Route::get('orders/{invoice}/courier-places', [CourierBookingController::class, 'places'])->whereNumber('invoice')->middleware('throttle:60,1,courier-places')->name('orders.courier-places');
    Route::post('orders/{invoice}/courier-status', [CourierBookingController::class, 'refresh'])->whereNumber('invoice')->middleware('throttle:30,1,courier-status')->name('orders.courier-status');
    Route::get('payments', [AdminPaymentController::class, 'index'])->name('payments.index');
    Route::get('support', [App\Http\Controllers\Admin\SupportController::class, 'index'])->name('support.index');
    Route::get('support/{ticket}', [App\Http\Controllers\Admin\SupportController::class, 'show'])->whereNumber('ticket')->name('support.show');
    Route::post('support/{ticket}/reply', [App\Http\Controllers\Admin\SupportController::class, 'reply'])->whereNumber('ticket')->middleware('throttle:60,1,support-staff-reply')->name('support.reply');
    Route::post('support/{ticket}/assign', [App\Http\Controllers\Admin\SupportController::class, 'assign'])->whereNumber('ticket')->name('support.assign');
    Route::post('support/{ticket}/close', [App\Http\Controllers\Admin\SupportController::class, 'close'])->whereNumber('ticket')->name('support.close');
    Route::post('support/{ticket}/reopen', [App\Http\Controllers\Admin\SupportController::class, 'reopen'])->whereNumber('ticket')->name('support.reopen');
    Route::post('payments/payout/{seller}', [AdminPaymentController::class, 'paidOut'])->whereNumber('seller')->name('payments.payout');
    Route::post('payments/{payment}/refunded', [AdminPaymentController::class, 'refunded'])->whereNumber('payment')->name('payments.refunded');
    Route::get('deliveries', [AdminDeliveryController::class, 'index'])->name('deliveries.index');
    Route::post('deliveries/{invoice}/arrived', [AdminDeliveryController::class, 'arrived'])->whereNumber('invoice')->name('deliveries.arrived');
    Route::post('deliveries/{invoice}/confirm', [AdminDeliveryController::class, 'confirm'])->whereNumber('invoice')->middleware('throttle:30,1,delivery-confirm')->name('deliveries.confirm');
    Route::post('orders/{invoice}/assign', [AdminDeliveryController::class, 'assign'])->whereNumber('invoice')->name('deliveries.assign');
    Route::get('delivery-cash', [AdminDeliveryController::class, 'cash'])->name('deliveries.cash');
    Route::post('delivery-cash/{person}', [AdminDeliveryController::class, 'handIn'])->whereNumber('person')->name('deliveries.hand-in');
    Route::get('delivery-ratings', [AdminDeliveryController::class, 'ratings'])->name('deliveries.ratings');
    Route::get('reviews', [AdminReviewController::class, 'index'])->name('reviews.index');
    Route::put('reviews/{review}/hide', [AdminReviewController::class, 'hide'])->whereNumber('review')->name('reviews.hide');
    Route::get('testimonials', [AdminTestimonialController::class, 'index'])->name('testimonials.index');
    Route::post('testimonials', [AdminTestimonialController::class, 'store'])->middleware('throttle:30,1,testimonial')->name('testimonials.store');
    Route::put('testimonials/{testimonial}', [AdminTestimonialController::class, 'update'])->whereNumber('testimonial')->name('testimonials.update');
    Route::delete('testimonials/{testimonial}', [AdminTestimonialController::class, 'destroy'])->whereNumber('testimonial')->name('testimonials.destroy');
    Route::get('catalog', [AdminCatalogController::class, 'index'])->name('catalog.index');
    Route::post('catalog', [AdminCatalogController::class, 'store'])->name('catalog.store');
    Route::post('catalog/{product}/approve', [AdminCatalogController::class, 'approve'])->whereNumber('product')->name('catalog.approve');
    Route::post('catalog/{product}/reject', [AdminCatalogController::class, 'reject'])->whereNumber('product')->name('catalog.reject');
    Route::get('tokens', [AdminTokenController::class, 'index'])->name('tokens.index');
    Route::post('tokens', [AdminTokenController::class, 'give'])->name('tokens.give');
    Route::post('tokens/{token}/take-back', [AdminTokenController::class, 'takeBack'])->whereNumber('token')->name('tokens.take-back');
    Route::get('quotes', [AdminQuoteController::class, 'index'])->name('quotes.index');
    Route::post('quotes/{quote}/answer', [AdminQuoteController::class, 'answer'])->name('quotes.answer');
    Route::get('market/reports', [AdminMarketController::class, 'reports'])->name('market.reports');
    Route::post('market/reports/{report}/resolve', [AdminMarketController::class, 'resolveReport'])->name('market.reports.resolve');
    Route::get('market/cases', [AdminMarketController::class, 'cases'])->name('market.cases');
    Route::post('market/cases/{case}/decide', [AdminMarketController::class, 'decideCase'])->name('market.cases.decide');
    Route::post('market/statements', [AdminMarketController::class, 'generateStatements'])->name('market.statements.generate');
    Route::post('market/statements/{statement}/verify', [AdminMarketController::class, 'verifyStatement'])->name('market.statements.verify');
    Route::post('market/statements/{statement}/reopen', [AdminMarketController::class, 'reopenStatement'])->name('market.statements.reopen');
    Route::post('market/sellers/{user}/penalty', [AdminMarketController::class, 'penalise'])->name('market.penalty');
    Route::put('market/sellers/{user}/penalty/lift', [AdminMarketController::class, 'liftPenalty'])->name('market.penalty.lift');
    Route::put('market/sellers/{user}/trust', [AdminMarketController::class, 'trust'])->name('market.trust');
    Route::put('market/sellers/{user}/selling', [AdminMarketController::class, 'liftStop'])->name('market.selling');
    Route::post('market/{listing}/approve', [AdminMarketController::class, 'approve'])->name('market.approve');
    Route::post('market/{listing}/reject', [AdminMarketController::class, 'reject'])->name('market.reject');
    Route::put('market/{listing}/commission', [AdminMarketController::class, 'commission'])->name('market.commission');
    // Sellers' security deposits (spec ch 14): kept with us against returns and unpaid commission; off until the owner turns it on in Settings.
    Route::get('market/deposits', [DepositController::class, 'index'])->name('market.deposits');
    Route::post('market/deposits/{user}/receive', [DepositController::class, 'receive'])->whereNumber('user')->name('market.deposits.receive');
    Route::post('market/deposits/{user}/give-back', [DepositController::class, 'giveBack'])->whereNumber('user')->name('market.deposits.give-back');
    Route::post('market/deposits/{user}/deduct', [DepositController::class, 'deduct'])->whereNumber('user')->name('market.deposits.deduct');
    Route::put('market/deposits/{user}/required', [DepositController::class, 'required'])->whereNumber('user')->name('market.deposits.required');
    Route::post('market/sectors', [AdminMarketController::class, 'storeSector'])->name('market.sectors.store');
    Route::put('market/sectors/{sector}', [AdminMarketController::class, 'updateSector'])->name('market.sectors.update');
    Route::post('market/sectors/{sector}/hero', [AdminMarketController::class, 'sectorHero'])->whereNumber('sector')->middleware('throttle:20,1,sector-hero')->name('market.sectors.hero');
    Route::put('market/sellers/{user}/shop-name', [AdminMarketController::class, 'shopName'])->name('market.shop-name');
    Route::get('help', [AdminHelpController::class, 'index'])->name('help.index');
    Route::post('help/{post}/approve', [AdminHelpController::class, 'approve'])->name('help.approve');
    Route::post('help/{post}/reject', [AdminHelpController::class, 'reject'])->name('help.reject');
    Route::put('help/{post}/hide', [AdminHelpController::class, 'hide'])->name('help.hide');
    Route::post('help/{post}/close', [AdminHelpController::class, 'close'])->name('help.close');
    Route::put('help/comments/{comment}/hide', [AdminHelpController::class, 'hideComment'])->name('help.comments.hide');
    Route::get('questions', [AdminQuestionController::class, 'index'])->name('questions.index');
    Route::put('questions/{question}/answer', [AdminQuestionController::class, 'answer'])->name('questions.answer');
    Route::put('questions/{question}/hide', [AdminQuestionController::class, 'hide'])->name('questions.hide');
    Route::put('seller-questions/{question}/answer', [AdminQuestionController::class, 'answerSeller'])->whereNumber('question')->name('seller-questions.answer');
    Route::put('seller-questions/{question}/hide', [AdminQuestionController::class, 'hideSeller'])->whereNumber('question')->name('seller-questions.hide');
    Route::get('deleted', [BinController::class, 'index'])->name('deleted.index');
    Route::post('deleted/{item}/restore', [BinController::class, 'restore'])->name('deleted.restore');
    Route::get('couriers', [CourierController::class, 'index'])->name('couriers.index');
    Route::post('couriers', [CourierController::class, 'store'])->name('couriers.store');
    Route::post('couriers/confirm', [CourierController::class, 'confirm'])->name('couriers.confirm');
    Route::put('couriers/{courier}', [CourierController::class, 'update'])->name('couriers.update');
    Route::delete('couriers/{courier}', [CourierController::class, 'destroy'])->name('couriers.destroy');
    Route::get('weights', [WeightController::class, 'index'])->name('weights.index');
    Route::put('weights/{medicine}', [WeightController::class, 'update'])->name('weights.update');

    // Which message goes by which channel (spec ch 23).
    Route::get('messages', [MessagesController::class, 'index'])->name('messages.index');
    Route::put('messages', [MessagesController::class, 'update'])->name('messages.update');

    // The owner's rules in one place: closed days, night hours, limits, return window (spec ch 25).
    Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::put('settings/{group}', [SettingsController::class, 'update'])->name('settings.update');

    // Addresses the owner has blocked (spec ch 26).
    Route::get('blocked-ips', [BlockedIpController::class, 'index'])->name('blocked-ips.index');
    Route::post('blocked-ips', [BlockedIpController::class, 'store'])->middleware('password.confirm')->name('blocked-ips.store');
    Route::delete('blocked-ips/{blockedIp}', [BlockedIpController::class, 'destroy'])->middleware('password.confirm')->name('blocked-ips.destroy');

    // Staff, roles and the activity log (spec ch 26). Changes ask for the password again.
    Route::get('staff', [StaffController::class, 'index'])->name('staff.index');
    Route::get('activity', [StaffController::class, 'activity'])->name('activity.index');
    Route::get('archive', [ArchiveController::class, 'index'])->name('archive.index');
    Route::post('archive/run', [ArchiveController::class, 'run'])->middleware('throttle:6,1,archive-run')->name('archive.run');
    Route::middleware('password.confirm')->group(function () {
        Route::post('staff', [StaffController::class, 'store'])->name('staff.store');
        Route::put('staff/{user}', [StaffController::class, 'update'])->name('staff.update');
        Route::post('staff/{user}/powers', [StaffController::class, 'lend'])->whereNumber('user')->name('staff.powers');
        Route::delete('powers/{power}', [StaffController::class, 'takeBack'])->whereNumber('power')->name('powers.take-back');
        Route::post('staff/{user}/close', [StaffController::class, 'close'])->name('staff.close');
        Route::post('staff/{user}/reopen', [StaffController::class, 'reopen'])->name('staff.reopen');
        Route::post('roles', [StaffController::class, 'storeRole'])->name('roles.store');
        Route::put('roles/{role}', [StaffController::class, 'updateRole'])->name('roles.update');
        Route::delete('roles/{role}', [StaffController::class, 'destroyRole'])->name('roles.destroy');
    });

    // What visitors search for and look at (spec ch 27).
    Route::get('behavior', [BehaviorController::class, 'index'])->name('behavior.index');

    // Sales and ranking reports (spec 6.9, ch 25): admin only, they carry profit.
    Route::get('reports', [SalesReportController::class, 'index'])->name('reports.index');
    Route::get('reports/export', [SalesReportController::class, 'export'])->name('reports.export');
    Route::post('reports/prepare', [SalesReportController::class, 'prepare'])->name('reports.prepare');
    Route::get('reports/vat', [VatReportController::class, 'index'])->name('reports.vat');
    Route::get('reports/vat/export', [VatReportController::class, 'export'])->name('reports.vat.export');

    // "Need admin approval" (spec 6.2): staff ask, the admin answers with one tap.
    Route::get('approvals', [ApprovalController::class, 'index'])->name('approvals.index');
    Route::post('approvals', [ApprovalController::class, 'store'])->name('approvals.store');
    Route::post('approvals/{approval}/approve', [ApprovalController::class, 'approve'])->name('approvals.approve');
    Route::post('approvals/{approval}/reject', [ApprovalController::class, 'reject'])->name('approvals.reject');

    // Returns (spec ch 12): staff record, only the admin approves.
    Route::get('returns', [ReturnController::class, 'index'])->name('returns.index');
    Route::get('returns/find', [ReturnController::class, 'find'])->name('returns.find');
    Route::post('returns', [ReturnController::class, 'store'])->name('returns.store');
    Route::post('returns/without-invoice', [ReturnController::class, 'storeWithoutInvoice'])->name('returns.without-invoice');
    Route::get('returns/{return}/voice', [ReturnController::class, 'voice'])->name('returns.voice');
    Route::post('returns/{return}/approve', [ReturnController::class, 'approve'])->name('returns.approve');
    Route::post('returns/{return}/reject', [ReturnController::class, 'reject'])->name('returns.reject');

    // Tier offers (spec 9.2): admin only, they are built from purchase information.
    Route::get('offers', [OfferController::class, 'index'])->name('offers.index');
    Route::post('offers', [OfferController::class, 'store'])->name('offers.store');
    Route::post('offer-products', [OfferController::class, 'storeProduct'])->name('offer-products.store');
    Route::post('offer-products/{offer}/off', [OfferController::class, 'turnOffProduct'])->name('offer-products.off');
    Route::post('offers/{offer}/off', [OfferController::class, 'turnOff'])->name('offers.off');
    Route::post('offers/{offer}/renew', [OfferController::class, 'renew'])->name('offers.renew');
    Route::post('offers/{offer}/leave', [OfferController::class, 'leave'])->name('offers.leave');

    // Prescriptions from shops: staff and admin only.
    Route::get('prescriptions', [PrescriptionController::class, 'index'])->name('prescriptions.index');
    Route::get('prescriptions/{prescription}/file', [PrescriptionController::class, 'file'])->name('prescriptions.file');
    Route::post('prescriptions/{prescription}/seen', [PrescriptionController::class, 'seen'])->name('prescriptions.seen');

    // End of day (spec 4.3.9): the day's takings against the cash counted. Admin only, staff do not see sales totals.
    Route::get('day', [DayClosingController::class, 'index'])->name('day.index');
    Route::post('day', [DayClosingController::class, 'store'])->name('day.store');
    Route::get('end-of-day', [DayClosingController::class, 'checklist'])->name('end-of-day');

    // Counter (spec 4.3.5): staff and admin.
    Route::get('counter', [CounterController::class, 'index'])->name('counter.index');
    Route::post('counter', [CounterController::class, 'open'])->name('counter.open');
    Route::post('counter/shops', [CounterController::class, 'newShop'])->name('counter.shops.store');
    Route::post('counter/notes', [CounterController::class, 'storeNote'])->name('counter.notes.store');
    Route::post('counter/wanted', [CounterController::class, 'wanted'])->middleware('throttle:60,1,counter-wanted')->name('counter.wanted');
    Route::post('counter/notes/{note}/draft', [CounterController::class, 'noteToDraft'])->name('counter.notes.draft');
    Route::get('counter/customers', [CounterController::class, 'customers'])->name('counter.customers');
    // The counter without internet (spec ch 8): the screen, a copy of the list for the device, and the sales sent back.
    Route::get('counter/offline', [OfflineCounterController::class, 'page'])->name('counter.offline');
    Route::get('counter/offline/snapshot', [OfflineCounterController::class, 'snapshot'])->name('counter.offline.snapshot');
    Route::post('counter/offline/sync', [OfflineCounterController::class, 'sync'])->middleware('throttle:30,1,offline-sync')->name('counter.offline.sync');
    Route::get('counter/{invoice}', [CounterController::class, 'show'])->name('counter.show');
    Route::post('counter-warnings/read', [CounterController::class, 'warningsRead'])->name('counter.warnings.read');
    Route::post('counter/{invoice}/working', [CounterController::class, 'working'])->name('counter.working');
    Route::post('counter/{invoice}/share', [CounterController::class, 'share'])->name('counter.share');
    Route::get('counter/{invoice}/search', [CounterController::class, 'search'])->name('counter.search');
    Route::post('counter/{invoice}/scan', [CounterController::class, 'scan'])->name('counter.scan');
    Route::post('counter/{invoice}/lines', [CounterController::class, 'addLine'])->name('counter.lines.add');
    Route::post('counter/{invoice}/offers/{offer}', [CounterController::class, 'addOffer'])->name('counter.offers.add');
    Route::put('counter/{invoice}/lines/{line}', [CounterController::class, 'setQuantity'])->name('counter.lines.quantity');
    Route::delete('counter/{invoice}/lines/{line}', [CounterController::class, 'removeLine'])->name('counter.lines.remove');
    Route::post('counter/{invoice}/lines/{line}/move', [CounterController::class, 'moveLine'])->name('counter.lines.move');
    Route::put('counter/{invoice}/discount', [CounterController::class, 'discount'])->name('counter.discount');
    Route::put('counter/{invoice}/lines/{line}/percent', [CounterController::class, 'linePercent'])->name('counter.lines.percent');
    Route::post('counter/{invoice}/merge', [CounterController::class, 'merge'])->name('counter.merge');
    Route::post('counter/{invoice}/keep', [CounterController::class, 'keep'])->name('counter.keep');
    Route::put('counter/{invoice}/customer', [CounterController::class, 'customer'])->name('counter.customer');
    Route::put('print-width', [CounterController::class, 'printWidth'])->name('print-width');
    Route::post('counter/{invoice}/correct', [CounterController::class, 'correct'])->name('counter.correct');
    Route::post('counter/{invoice}/printed', [CounterController::class, 'printed'])->name('counter.printed');
    Route::post('counter/{invoice}/finalise', [CounterController::class, 'finalise'])->name('counter.finalise');
    Route::post('counter/{invoice}/cancel', [CounterController::class, 'cancel'])->name('counter.cancel');

    Route::get('tiers', [TierController::class, 'index'])->name('tiers.index');
    Route::post('tiers', [TierController::class, 'store'])->name('tiers.store');
    Route::put('tiers/{tier}', [TierController::class, 'update'])->name('tiers.update');
    Route::delete('tiers/{tier}', [TierController::class, 'destroy'])->name('tiers.destroy');
    Route::put('selling-rates/company/{company}', [SellingRateController::class, 'company'])->middleware('password.confirm')->name('selling-rates.company');
    Route::put('selling-rates/medicine/{medicine}', [SellingRateController::class, 'medicine'])->middleware('password.confirm')->name('selling-rates.medicine');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
