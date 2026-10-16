<?php

namespace App\Http\Controllers;

use App\Models\ProductRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "I was looking for this" (spec ch 13). Open to everyone, including guests, so it stores no personal
 * data beyond the optional account id. Spam is limited by a rate limit and by counting one request per
 * visitor session for each distinct search.
 */
class ProductRequestController extends Controller
{
    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'query' => ['required', 'string', 'min:2', 'max:120'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        $normalized = ProductRequest::normalize($data['query']);
        $seen = $request->session()->get('product_requests', []);

        // The same visitor asking for the same thing twice is one voice, not two.
        if (! in_array($normalized, $seen, true)) {
            ProductRequest::create([
                'query' => $data['query'],
                'normalized' => $normalized,
                'note' => $data['note'] ?? null,
                'user_id' => $request->user()?->id,
            ]);
            $request->session()->put('product_requests', array_slice([...$seen, $normalized], -50));
        }

        // The small "I was looking for this" button posts with fetch and wants JSON; forms get a redirect.
        if ($request->expectsJson()) {
            return response()->json(['status' => 'noted']);
        }

        return back()->with('status', __('Noted. We will try to stock it.'));
    }
}
