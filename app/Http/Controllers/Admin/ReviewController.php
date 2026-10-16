<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ListingReview;
use App\Services\Audit\ActivityLogger;
use App\Services\Market\Reviews;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Reviews on the market: we can hide one that breaks the rules (spec ch 13). */
class ReviewController extends Controller
{
    public function __construct(private Reviews $reviews, private ActivityLogger $activity) {}

    public function index(): Response
    {
        $this->authorize('manage-customers');

        return Inertia::render('admin/reviews', [
            'reviews' => ListingReview::with('listing:id,title', 'photos')->latest('id')->limit(80)->get()->map(fn (ListingReview $r) => [
                'id' => $r->id, 'product' => $r->listing->title, 'rating' => $r->rating, 'body' => $r->body, 'hidden' => $r->hidden, 'at' => $r->created_at->toIso8601String(),
                'photos' => $r->photos->map(fn ($p) => route('market.review-photo', $p))->all(),
            ]),
        ]);
    }

    public function hide(Request $request, ListingReview $review): RedirectResponse
    {
        $this->authorize('manage-customers');
        $data = $request->validate(['hidden' => ['required', 'boolean']]);

        $this->reviews->hide($review, (bool) $data['hidden']);
        $this->activity->record($data['hidden'] ? 'review.hidden' : 'review.shown', null, ['review' => $review->id], $request->user());

        return back()->with('status', $data['hidden'] ? __('Hidden.') : __('Shown again.'));
    }
}
