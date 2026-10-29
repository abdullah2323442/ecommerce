<?php

namespace App\Http\Controllers;

use App\Models\ProductRequest;
use App\Services\Media\ImageCompressor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "I was looking for this" (spec ch 13). Open to everyone, including guests, so it stores no personal
 * data beyond the optional account id. Spam is limited by a rate limit and by counting one request per
 * visitor session for each distinct search in each sector. It works in every sector, and may carry a note and a photo.
 */
class ProductRequestController extends Controller
{
    public function store(Request $request, ImageCompressor $images): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'query' => ['required', 'string', 'min:2', 'max:120'],
            'note' => ['nullable', 'string', 'max:300'],
            'sector' => ['nullable', 'string', 'max:30', 'regex:/^[a-z0-9-]+$/'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
        ]);

        $normalized = ProductRequest::normalize($data['query']);
        $sector = $data['sector'] ?? null;
        $key = $sector.'|'.$normalized;
        $seen = $request->session()->get('product_requests', []);

        // The same visitor asking for the same thing twice, in the same sector, is one voice, not two.
        if (! in_array($key, $seen, true)) {
            ProductRequest::create([
                'query' => $data['query'],
                'normalized' => $normalized,
                'note' => $data['note'] ?? null,
                'sector' => $sector,
                'photo_path' => $request->hasFile('photo') ? $images->store($request->file('photo'), 'requests', 900) : null,
                'user_id' => $request->user()?->id,
            ]);
            $request->session()->put('product_requests', array_slice([...$seen, $key], -50));
        }

        // The small "I was looking for this" button posts with fetch and wants JSON; forms get a redirect.
        if ($request->expectsJson()) {
            return response()->json(['status' => 'noted']);
        }

        return back()->with('status', __('Noted. We will try to stock it.'));
    }
}
