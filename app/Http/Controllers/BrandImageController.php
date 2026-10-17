<?php

namespace App\Http\Controllers;

use App\Services\Site\Site;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The shop's logo and small icon, shown to everyone. The address carries a version so a new picture shows at once and the old one can be cached for a long time. */
class BrandImageController extends Controller
{
    /** A picture used in a home page block, by its file name. Only files in our own brand folder, only plain names. */
    public function block(string $name): StreamedResponse
    {
        abort_unless(preg_match('/^[A-Za-z0-9_-]{8,64}\.(webp|jpg|jpeg|png)$/', $name) === 1 && Storage::exists('brand/'.$name), 404);

        return Storage::response('brand/'.$name, null, ['Cache-Control' => 'public, max-age=31536000, immutable', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function show(Site $site, string $which): StreamedResponse
    {
        abort_unless(in_array($which, ['logo', 'favicon'], true), 404);
        $path = $site->group('brand')[$which] ?? null;
        abort_unless($path && Storage::exists($path), 404);

        return Storage::response($path, null, ['Cache-Control' => 'public, max-age=31536000, immutable']);
    }
}
