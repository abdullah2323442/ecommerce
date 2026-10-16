<?php

namespace App\Http\Controllers;

use App\Services\Market\Marketplace;
use App\Services\Medicines\MedicineSearch;
use App\Support\MedicinePresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** One search for the whole site: medicines and the market's products together, each in its own group with a link to see them all. */
class SiteSearchController extends Controller
{
    private const EACH = 10;

    public function __construct(private MedicineSearch $medicines, private Marketplace $market) {}

    public function index(Request $request): Response
    {
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 80);
        $medicines = $products = [];
        $productTotal = 0;

        if (mb_strlen($q) >= 2) {
            $medicines = MedicinePresenter::many($this->medicines->search($q, self::EACH));
            $needle = mb_strtolower($q);
            $found = $this->market->visible($request->user())
                ->filter(fn ($r) => str_contains(mb_strtolower($r['listing']->title.' '.$r['listing']->alt_names), $needle))
                ->unique(fn ($r) => $r['listing']->catalog_product_id !== null ? 'c'.$r['listing']->catalog_product_id : 'l'.$r['listing']->id)->values();
            $productTotal = $found->count();
            $products = $found->take(self::EACH)->map(fn ($r) => [
                'id' => $r['listing']->id, 'title' => $r['listing']->title, 'price' => (float) $r['price'], 'photo' => $r['listing']->photo_path !== null ? route('market.photo', $r['listing']->id) : null,
            ])->all();
        }

        return Inertia::render('search', ['q' => $q, 'medicines' => $medicines, 'products' => $products, 'productTotal' => $productTotal]);
    }
}
