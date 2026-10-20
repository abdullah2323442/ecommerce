<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Testimonial;
use App\Services\Audit\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/** What buyers say on the home page: the owner adds, orders, hides and removes them. */
class TestimonialController extends Controller
{
    public function __construct(private ActivityLogger $activity) {}

    public function index(): Response
    {
        $this->authorize('manage-customers');

        return Inertia::render('admin/testimonials', ['testimonials' => Testimonial::orderBy('sort')->orderBy('id')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('manage-customers');
        $t = Testimonial::create($this->data($request));
        $this->activity->record('testimonial.added', null, ['testimonial' => $t->id], $request->user());
        Cache::forget('home.testimonials');

        return back()->with('status', __('Saved.'));
    }

    public function update(Request $request, Testimonial $testimonial): RedirectResponse
    {
        $this->authorize('manage-customers');
        $testimonial->update($this->data($request));
        $this->activity->record('testimonial.changed', null, ['testimonial' => $testimonial->id], $request->user());
        Cache::forget('home.testimonials');

        return back()->with('status', __('Saved.'));
    }

    public function destroy(Request $request, Testimonial $testimonial): RedirectResponse
    {
        $this->authorize('manage-customers');
        $testimonial->delete();
        $this->activity->record('testimonial.removed', null, ['testimonial' => $testimonial->id], $request->user());
        Cache::forget('home.testimonials');

        return back()->with('status', __('Removed.'));
    }

    private function data(Request $request): array
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'place' => ['nullable', 'string', 'max:80'],
            'quote' => ['required', 'string', 'max:400'],
            'quote_bn' => ['nullable', 'string', 'max:600'],
            'rating' => ['required', 'integer', 'between:1,5'],
            'is_active' => ['required', 'boolean'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ]);

        return $d + ['sort' => (int) ($d['sort'] ?? 0)];
    }
}
