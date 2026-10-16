<?php

namespace App\Http\Controllers;

use App\Models\Medicine;
use App\Models\ProductQuestion;
use App\Models\Setting;
use App\Services\Analytics\Behavior;
use App\Services\Medicines\MedicineSearch;
use App\Services\Medicines\Together;
use App\Services\Mpo\MpoService;
use App\Services\Sales\PreOrders;
use App\Services\Sales\QuantitySteps;
use App\Services\Stock\StockService;
use App\Support\MedicinePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

class MedicineSearchController extends Controller
{
    private const PER_PAGE = 30;

    private const MAX_MATCHES = 150;

    public function __construct(private MedicineSearch $search, private Behavior $behavior) {}

    /** Search page. Open to everyone (spec ch 11); never exposes stock. */
    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $results = $q === '' ? null : $this->page($request, $q);

        // What people look for (first page only, so paging is not counted again).
        if ($results !== null && (int) $request->query('page', 1) <= 1) {
            $this->behavior->record('search', null, $q, ['found' => $results->total()]);
        }

        // With nothing typed the page lists every medicine, A to Z, a page at a time.
        $browse = $q === '' ? Medicine::active()->with('company')->orderBy('brand_name')->orderBy('strength')->orderBy('id')->paginate(self::PER_PAGE)->through(fn (Medicine $m) => MedicinePresenter::one($m)) : null;

        return Inertia::render('medicines/index', [
            'query' => $q,
            'results' => $results,
            'browse' => $browse,
        ]);
    }

    /** "This was 450 last month" (spec ch 13): the printed price before its last change, while that change is recent. Builds trust. */
    private function priceWas(Medicine $medicine): ?array
    {
        $days = max(1, (int) Setting::read('catalog.price_history_days', 90));
        if ($medicine->mrp_changed_at === null || $medicine->mrp_before === null || $medicine->mrp_changed_at->lt(now()->subDays($days))) {
            return null;
        }

        $now = StockService::unitMrp($medicine);

        return abs($now - (float) $medicine->mrp_before) < 0.005 ? null : ['was' => (float) $medicine->mrp_before, 'now' => $now, 'at' => $medicine->mrp_changed_at->toDateString(), 'unit' => $medicine->packaging_type === 'box' ? 'box' : 'piece'];
    }

    /** The ranked matches, cut into pages of PER_PAGE so a deep link can reach all of them (up to MAX_MATCHES). */
    private function page(Request $request, string $q): LengthAwarePaginator
    {
        $all = MedicinePresenter::many($this->search->search($q, self::MAX_MATCHES));
        $page = max(1, (int) $request->query('page', 1));

        return new LengthAwarePaginator(array_slice($all, ($page - 1) * self::PER_PAGE, self::PER_PAGE), count($all), self::PER_PAGE, $page, [
            'path' => $request->url(),
            'query' => ['q' => $q],
        ]);
    }

    public function suggest(Request $request): JsonResponse
    {
        $request->validate(['q' => ['required', 'string', 'max:80']]);

        return response()->json([
            'data' => MedicinePresenter::many($this->search->search($request->string('q')->toString())),
        ]);
    }

    public function show(Medicine $medicine): Response
    {
        abort_unless($medicine->is_active, 404);
        $this->behavior->record('view', $medicine->id);

        return Inertia::render('medicines/show', [
            'medicine' => MedicinePresenter::one($medicine->load('company:id,name,logo_url,logo_path,preorder_days')),
            // Other sellers of this medicine, masked as "Anonymous N (store)" and priced for the viewer's own market (spec ch 10).
            'mpoOffers' => app(MpoService::class)->offersFor($medicine, request()->user() ? (app(MpoService::class)->marketOf(request()->user()) ?? 'public') : 'public', request()->user()),
            'preorderDays' => app(PreOrders::class)->daysFor($medicine),
            'steps' => app(QuantitySteps::class)->forPage($medicine),
            'together' => MedicinePresenter::many(app(Together::class)->for($medicine)),
            'priceWas' => $this->priceWas($medicine),
            'questions' => ProductQuestion::public()->where('medicine_id', $medicine->id)->latest('answered_at')->limit(30)
                ->get()->map(fn (ProductQuestion $q) => ['id' => $q->id, 'question' => $q->question, 'answer' => $q->answer, 'at' => $q->answered_at->toIso8601String()]),
            // What the signed-in buyer asked that is still waiting, so they see it was received.
            'myWaiting' => request()->user() ? ProductQuestion::waiting()->where('medicine_id', $medicine->id)->where('user_id', request()->user()->id)->count() : 0,
            'alternatives' => MedicinePresenter::many($this->search->alternatives($medicine)),
        ]);
    }
}
