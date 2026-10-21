<?php

namespace App\Http\Controllers;

use App\Services\Site\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * The installable app (spec ch 3 and 27: the customer app, with its update message). One website that a phone can install to its home screen: a manifest
 * made from the shop's own name and colour (Admin > Site design), icons drawn from its logo, a service worker, and a page for when there is no line. The
 * worker file carries the build's fingerprint, so every new build is seen by installed copies, which then tell their user an update is ready.
 */
class PwaController extends Controller
{
    private const ICONS = ['192' => 192, '512' => 512, 'maskable-512' => 512, 'apple-180' => 180];

    public function manifest(Site $site): JsonResponse
    {
        $b = $site->forViewer('bn')['brand'];
        $name = (string) $b['name'];
        $v = substr(md5(json_encode([$b['logo'], $b['theme_color']])), 0, 8);

        return response()->json([
            'name' => $name, 'short_name' => mb_substr($name, 0, 12), 'description' => (string) ($b['tagline'] ?? ''), 'id' => '/', 'start_url' => '/', 'scope' => '/', 'display' => 'standalone', 'orientation' => 'portrait',
            'background_color' => '#ffffff', 'theme_color' => $b['theme_color'], 'lang' => 'bn', 'dir' => 'ltr',
            'icons' => [
                ['src' => route('pwa.icon', ['name' => '192', 'v' => $v], false), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => route('pwa.icon', ['name' => '512', 'v' => $v], false), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => route('pwa.icon', ['name' => 'maskable-512', 'v' => $v], false), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
            'shortcuts' => [
                ['name' => __('Your orders', [], 'bn'), 'url' => '/my-orders'],
                ['name' => __('Cart', [], 'bn'), 'url' => '/cart'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json', 'Cache-Control' => 'public, max-age=3600']);
    }

    /** The icon: the shop's logo on its colour, or a plain drop when there is no logo. Drawn here so the owner never has to make five sizes. */
    public function icon(Site $site, string $name): Response
    {
        abort_unless(isset(self::ICONS[$name]), 404);
        abort_unless(function_exists('imagecreatetruecolor'), 404);

        $px = self::ICONS[$name];
        $b = $site->forViewer('bn')['brand'];
        [$r, $g, $bl] = sscanf((string) $b['theme_color'], '#%02x%02x%02x');
        $img = imagecreatetruecolor($px, $px);
        imagealphablending($img, true);
        imagefill($img, 0, 0, imagecolorallocate($img, $r, $g, $bl));

        // A maskable icon keeps its picture well inside the middle, because phones cut it to a circle or a rounded square.
        $pad = $name === 'maskable-512' ? 0.24 : 0.14;
        $box = (int) round($px * (1 - 2 * $pad));
        $path = $site->group('brand')['logo'] ?? null;
        $logo = $path && Storage::exists($path) ? @imagecreatefromstring((string) Storage::get($path)) : false;

        if ($logo) {
            $w = imagesx($logo);
            $h = imagesy($logo);
            $scale = min($box / $w, $box / $h);
            $nw = max(1, (int) round($w * $scale));
            $nh = max(1, (int) round($h * $scale));
            imagecopyresampled($img, $logo, (int) (($px - $nw) / 2), (int) (($px - $nh) / 2), 0, 0, $nw, $nh, $w, $h);
        } else {
            $this->drop($img, $px, $box);
        }

        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();

        return response($png, 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'public, max-age=86400', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** The service worker, with the build's fingerprint on its last line so a new build is a new worker. */
    public function worker(): Response
    {
        clearstatcache();
        $manifest = public_path('build/manifest.json');
        $build = is_file($manifest) ? md5((string) filemtime($manifest).'|'.filesize($manifest)) : 'dev';

        return response(file_get_contents(resource_path('pwa/sw.js'))."\n// build: {$build}\n", 200, [
            'Content-Type' => 'application/javascript; charset=utf-8', 'Service-Worker-Allowed' => '/', 'Cache-Control' => 'no-cache, max-age=0', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** What a phone shows when the line is down and the page was not kept. */
    public function offline(Site $site): Response
    {
        $name = e((string) $site->forViewer()['brand']['name']);
        $color = e((string) $site->forViewer()['brand']['theme_color']);
        $title = e(__('No internet right now'));
        $text = e(__('Please check your connection. The page opens again as soon as the line is back.'));
        $retry = e(__('Try again'));

        return response('<!doctype html><html lang="'.e(app()->getLocale())."\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><meta name=\"theme-color\" content=\"{$color}\"><title>{$name}</title>"
            ."<style>body{margin:0;min-height:100vh;display:grid;place-items:center;font-family:system-ui,sans-serif;background:#fff;color:#14120f}main{max-width:22rem;padding:1.5rem;text-align:center}h1{font-size:1.4rem}button{margin-top:1rem;padding:.7rem 1.4rem;border:0;border-radius:.6rem;background:{$color};color:#fff;font-size:1rem}</style></head>"
            ."<body><main><h1>{$title}</h1><p>{$text}</p><button onclick=\"location.reload()\">{$retry}</button></main></body></html>", 200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-cache']);
    }

    /** A white drop on the colour: a circle with a point above it. */
    private function drop(\GdImage $img, int $px, int $box): void
    {
        $white = imagecolorallocate($img, 255, 255, 255);
        $cx = $px / 2;
        $r = $box * 0.34;
        $cy = $px / 2 + $box * 0.12;
        imagefilledellipse($img, (int) $cx, (int) $cy, (int) ($r * 2), (int) ($r * 2), $white);
        imagefilledpolygon($img, [(int) $cx, (int) ($px / 2 - $box * 0.42), (int) ($cx - $r * 0.94), (int) ($cy - $r * 0.34), (int) ($cx + $r * 0.94), (int) ($cy - $r * 0.34)], $white);
    }
}
