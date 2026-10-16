<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AccountType;
use App\Http\Controllers\Controller;
use App\Models\Tier;
use App\Models\TradeApplication;
use App\Services\Audit\ActivityLogger;
use App\Services\Messages\Messenger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Application review (spec ch 25): read the licence, then open the right account or say why not. The licence is a
 * private file; every look at it is logged.
 */
class ApplicationController extends Controller
{
    public function __construct(private ActivityLogger $activity) {}

    public function index(): Response
    {
        $this->authorize('manage-customers');

        return Inertia::render('admin/applications', [
            'pending' => $this->rows(TradeApplication::where('status', 'pending')->oldest('id')->get()),
            'done' => $this->rows(TradeApplication::whereIn('status', ['approved', 'rejected'])->latest('reviewed_at')->limit(20)->get()),
            'tiers' => Tier::orderBy('name')->get(['id', 'name']),
            'categories' => collect(\App\Support\Categories::all())->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
        ]);
    }

    public function file(TradeApplication $application, string $which): StreamedResponse
    {
        $this->authorize('manage-customers');
        [$path, $name] = $which === 'second' ? [$application->second_path, $application->second_name] : [$application->license_path, $application->license_name];
        abort_unless($path && Storage::disk('local')->exists($path), 404);
        $this->activity->record('application.licence_viewed', $application, ['file' => $which], request()->user());

        return Storage::disk('local')->response($path, $name ?? 'licence', ['Cache-Control' => 'private, no-store']);
    }

    /** Approve: the admin picks the account (a shop buyer or a bulk buyer), the tier and, for bulk, the categories and terms. */
    public function approve(Request $request, TradeApplication $application): RedirectResponse
    {
        $this->authorize('manage-customers');
        abort_unless($application->status === 'pending', 404);

        // A restaurant application is answered by opening the restaurant, not a buying account.
        if ($application->business_kind === 'restaurant') {
            return $this->approveRestaurant($request, $application);
        }

        $data = $request->validate([
            'account_type' => ['required', Rule::in([AccountType::Paikari->value, AccountType::Wholesaler->value])],
            'tier_id' => ['nullable', 'integer', Rule::exists('tiers', 'id')],
            'categories' => ['nullable', 'array'],
            'categories.*' => ['in:'.implode(',', array_keys(\App\Support\Categories::all()))],
            'moq' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'pay_cod' => ['sometimes', 'boolean'],
            'pay_advance' => ['sometimes', 'boolean'],
        ]);

        $bulk = $data['account_type'] === AccountType::Wholesaler->value;
        $user = $application->user;

        $user->forceFill([
            'account_type' => $data['account_type'],
            'business_name' => $application->business_name,
            'phone' => $user->phone ?: $application->phone,
            'tier_id' => $data['tier_id'] ?? $user->tier_id,
            'categories' => $bulk ? array_values($data['categories'] ?? $application->categories ?? ['medicine']) : null,
            'moq' => $bulk ? ($data['moq'] ?? null) : null,
            'pay_cod' => $bulk ? (bool) ($data['pay_cod'] ?? true) : true,
            'pay_advance' => $bulk ? (bool) ($data['pay_advance'] ?? true) : true,
        ])->save();

        $application->update(['status' => 'approved', 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'review_note' => null]);
        $this->activity->record('application.approved', $application, ['account_type' => $data['account_type']], $request->user());
        app(Messenger::class)->send($user, 'application_approved', ['name' => $application->business_name]);

        return back()->with('status', __('Account opened.'));
    }

    private function approveRestaurant(Request $request, TradeApplication $application): RedirectResponse
    {
        $data = $request->validate([
            'area' => ['required', 'string', 'max:80'], 'commission_kind' => ['required', 'in:percent,taka'], 'commission_value' => ['required', 'numeric', 'min:0', 'max:100000'],
        ]);

        try {
            app(\App\Services\Food\Restaurants::class)->openFor($application->user, [
                'name' => $application->business_name, 'phone' => $application->phone, 'address' => $application->address,
                'area' => $data['area'], 'commission_kind' => $data['commission_kind'], 'commission_value' => $data['commission_value'],
            ], $request->user());
        } catch (\App\Services\Stock\StockException $e) {
            return back()->withErrors(['application' => __($e->getMessage())]);
        }

        $application->update(['status' => 'approved', 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'review_note' => null]);
        $this->activity->record('application.approved', $application, ['account_type' => 'food_vendor'], $request->user());
        app(Messenger::class)->send($application->user, 'application_approved', ['name' => $application->business_name]);

        return back()->with('status', __('Restaurant opened.'));
    }

    public function reject(Request $request, TradeApplication $application): RedirectResponse
    {
        $this->authorize('manage-customers');
        abort_unless($application->status === 'pending', 404);
        $data = $request->validate(['reason' => ['required', 'string', 'max:300']]);

        $application->update(['status' => 'rejected', 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'review_note' => $data['reason']]);
        $this->activity->record('application.rejected', $application, ['reason' => $data['reason']], $request->user());

        return back()->with('status', __('Application declined. The applicant can see the reason.'));
    }

    private function rows($items)
    {
        $items->load('user:id,name,email,phone');

        return $items->map(fn (TradeApplication $a) => [
            'id' => $a->id, 'status' => $a->status, 'business_name' => $a->business_name, 'kind' => $a->business_kind, 'categories' => $a->categories,
            'person' => $a->user?->name, 'email' => $a->user?->email, 'phone' => $a->phone, 'address' => $a->address, 'license_number' => $a->license_number,
            'license_name' => $a->license_name, 'second_name' => $a->second_name, 'note' => $a->note, 'review_note' => $a->review_note,
            'waiting_days' => (int) $a->created_at->diffInDays(now()), 'sent_at' => $a->created_at->toIso8601String(),
        ])->values();
    }
}
