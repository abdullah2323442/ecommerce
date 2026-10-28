<?php

namespace App\Http\Controllers;

use App\Services\Site\Site;
use App\Services\Supplier\SupplierApp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The supplier app (spec ch 3): its own home screen and its own install file, on the same website and the same login as the customer app. */
class SupplierAppController extends Controller
{
    public function __construct(private SupplierApp $app) {}

    public function home(Request $request): Response
    {
        abort_unless($this->app->isSupplier($request->user()), 404);

        return Inertia::render('supplier', $this->app->home($request->user()) + ['name' => $request->user()->business_name ?: $request->user()->name]);
    }

    /** The install file of the supplier app: its own name and its own start page, so a phone can hold both apps side by side. */
    public function manifest(Site $site): JsonResponse
    {
        $b = $site->forViewer('bn')['brand'];
        $name = (string) $b['name'];
        $v = substr(md5(json_encode([$b['logo'], $b['theme_color']])), 0, 8);

        return response()->json([
            'name' => $name.' '.__('Supplier', [], 'bn'), 'short_name' => mb_substr(__('Supplier', [], 'bn'), 0, 12), 'description' => __('Orders, products and reports for people who sell here.', [], 'bn'),
            'id' => '/supplier', 'start_url' => '/supplier', 'scope' => '/', 'display' => 'standalone', 'orientation' => 'portrait', 'background_color' => '#ffffff', 'theme_color' => $b['theme_color'], 'lang' => 'bn', 'dir' => 'ltr',
            'icons' => [
                ['src' => route('pwa.icon', ['name' => '192', 'v' => $v], false), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => route('pwa.icon', ['name' => '512', 'v' => $v], false), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => route('pwa.icon', ['name' => 'maskable-512', 'v' => $v], false), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json', 'Cache-Control' => 'public, max-age=3600']);
    }
}
