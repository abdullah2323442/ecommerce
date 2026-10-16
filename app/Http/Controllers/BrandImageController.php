<?php

namespace App\Http\Controllers;

use App\Services\Site\Site;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The shop's logo and small icon, shown to everyone. The address carries a version so a new picture shows at once and the old one can be cached for a long time. */
class BrandImageController extends Controller
{
    public function show(Site $site, string $which): StreamedResponse
    {
        abort_unless(in_array($which, ['logo', 'favicon'], true), 404);
        $path = $site->group('brand')[$which] ?? null;
        abort_unless($path && Storage::exists($path), 404);

        return Storage::response($path, null, ['Cache-Control' => 'public, max-age=31536000, immutable']);
    }
}
