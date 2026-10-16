<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Game;
use App\Models\Listing;
use App\Models\Medicine;
use App\Models\Restaurant;
use App\Services\Market\Marketplace;
use App\Services\Market\Shops;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * What search engines may find (spec ch 27: SEO). Only what any visitor can already open: the shop's own medicine pages, companies, the market products a
 * stranger is allowed to see, the shops that have a page, restaurants and games that are open. Nothing from a shop's, a trader's or the staff's side, no order
 * pages, and nothing a stranger could not see (spec ch 05), so a hidden product is not even listed.
 */
class SitemapController extends Controller
{
    private const PER_FILE = 5000;

    public function __construct(private Marketplace $market, private Shops $shops) {}

    /** The index: one file for the pages, and a file for each lot of medicines. */
    public function index(): Response
    {
        $files = [route('sitemap.pages')];
        $lots = (int) ceil(Medicine::where('is_active', true)->count() / self::PER_FILE);

        for ($i = 1; $i <= max(1, $lots); $i++) {
            $files[] = route('sitemap.medicines', $i);
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            .collect($files)->map(fn ($f) => '<sitemap><loc>'.e($f).'</loc></sitemap>')->implode('').'</sitemapindex>';

        return $this->xml($xml);
    }

    public function pages(): Response
    {
        $urls = Cache::remember('sitemap.pages', 3600, function () {
            $urls = collect([route('home'), route('medicines.index'), route('companies.index'), route('market.index'), route('food.index'), route('gaming.index'), route('help.index'), route('info.faq'), route('info.returns'), route('info.terms')])
                ->map(fn ($u) => ['loc' => $u]);

            $urls = $urls->merge(Company::orderBy('id')->limit(self::PER_FILE)->get(['id', 'updated_at'])->map(fn ($c) => ['loc' => route('companies.show', $c), 'at' => $c->updated_at]));

            // A product only appears if a stranger could open it today (published, shown, in a sector that is open, not past its date).
            $urls = $urls->merge(Listing::shown()->with(['sector', 'seller'])->orderBy('id')->limit(self::PER_FILE)->get()
                ->filter(fn (Listing $l) => $this->market->priceFor($l, null) !== null)
                ->map(fn (Listing $l) => ['loc' => route('market.show', $l), 'at' => $l->updated_at]));

            $sellers = Listing::shown()->with('seller')->get()->pluck('seller')->filter()->unique('id')->filter(fn ($s) => $this->shops->hasPage($s));
            $urls = $urls->merge($sellers->map(fn ($s) => ['loc' => route('shop.show', $s->id)]));

            $urls = $urls->merge(Restaurant::where('status', 'active')->orderBy('id')->get(['id', 'updated_at'])->map(fn ($r) => ['loc' => route('food.show', $r), 'at' => $r->updated_at]));
            $urls = $urls->merge(Game::where('is_active', true)->orderBy('sort')->get(['slug', 'updated_at'])->map(fn ($g) => ['loc' => route('gaming.show', $g->slug), 'at' => $g->updated_at]));

            return $urls->all();
        });

        return $this->xml($this->urlset($urls));
    }

    public function medicines(int $page): Response
    {
        abort_if($page < 1, 404);

        $urls = Cache::remember("sitemap.medicines.$page", 3600, fn () => Medicine::where('is_active', true)->orderBy('id')->skip(($page - 1) * self::PER_FILE)->limit(self::PER_FILE)->get(['id', 'updated_at'])
            ->map(fn (Medicine $m) => ['loc' => route('medicines.show', $m), 'at' => $m->updated_at])->all());

        return $this->xml($this->urlset($urls));
    }

    /** What robots may not read: the private sides of the site, and every page that is only for the person holding its link. */
    public function robots(): Response
    {
        $lines = ['User-agent: *'];

        foreach (['/admin', '/dashboard', '/settings', '/paikari', '/wholesale', '/mpo', '/craftsman', '/food/vendor', '/orders/', '/market/orders/', '/market-orders', '/market-offers', '/food/orders/', '/gaming/orders/', '/gaming-orders', '/custom', '/inbox', '/wallet', '/cart', '/checkout', '/my-orders', '/preorders', '/login', '/register', '/forgot-password', '/reset-password', '/memo/', '/receipt/', '/business', '/quotes'] as $path) {
            $lines[] = 'Disallow: '.$path;
        }

        $lines[] = '';
        $lines[] = 'Sitemap: '.route('sitemap.index');

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /** @param  array<int, array{loc: string, at?: mixed}>  $urls */
    private function urlset(array $urls): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            .collect($urls)->map(fn (array $u) => '<url><loc>'.e($u['loc']).'</loc>'.(isset($u['at']) && $u['at'] ? '<lastmod>'.$u['at']->toAtomString().'</lastmod>' : '').'</url>')->implode('')
            .'</urlset>';
    }

    private function xml(string $body): Response
    {
        return response($body, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
