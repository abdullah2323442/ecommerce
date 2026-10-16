<?php

namespace Tests\Concerns;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Fetch the props Inertia would really send to the browser, with lazy values resolved.
 *
 * Reading `viewData('page')` is not enough for leak checks: shared props such as the translation
 * dictionary are closures that stay unevaluated there, so a test would pass while the browser
 * still received the data. Asking for the JSON response resolves everything.
 */
trait ResolvesInertiaProps
{
    /** @return array<string, mixed> */
    protected function inertiaProps(string $url, ?User $as = null): array
    {
        $request = $as ? $this->actingAs($as) : $this;

        $response = $request->withHeaders([
            'X-Inertia' => 'true',
            // Inertia answers 409 unless the asset version matches, so use the one the app itself computes.
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/')),
        ])->get($url);

        $response->assertOk();

        return $response->json('props');
    }

    /** The same props, lower-cased and flattened to one searchable string. */
    protected function inertiaPayload(string $url, ?User $as = null, bool $withoutTranslations = false): string
    {
        $props = $this->inertiaProps($url, $as);

        if ($withoutTranslations) {
            unset($props['translations']);
        }

        return mb_strtolower(json_encode($props, JSON_UNESCAPED_UNICODE));
    }
}
