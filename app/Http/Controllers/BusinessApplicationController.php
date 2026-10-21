<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Models\TradeApplication;
use App\Services\Media\ImageCompressor;
use App\Support\Categories;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Apply for a business account (spec ch 3, 14). The page and its words are the same for everyone: it never says which
 * kind of account will be opened, so a visitor learns nothing about the sections behind it. The licence is a private file.
 */
class BusinessApplicationController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        // Someone who already has a business account has nothing to apply for.
        if (! in_array($user->account_type, [AccountType::Public], true)) {
            return redirect($user->account_type->homePath());
        }

        $latest = TradeApplication::where('user_id', $user->id)->latest('id')->first();

        return Inertia::render('business', [
            'categories' => collect(Categories::all())->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'application' => $latest ? [
                'status' => $latest->status, 'business_name' => $latest->business_name, 'review_note' => $latest->status === 'rejected' ? $latest->review_note : null,
                'sent_at' => $latest->created_at->toIso8601String(),
            ] : null,
            'defaults' => ['business_name' => $user->business_name, 'phone' => $user->phone],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->account_type === AccountType::Public, 403);

        if (TradeApplication::where('user_id', $user->id)->where('status', 'pending')->exists()) {
            return back()->withErrors(['business_name' => __('Your application is already with us. We will reply after we check it.')]);
        }

        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:150'],
            'business_kind' => ['required', 'in:shop,trader,restaurant'],
            'categories' => [Rule::requiredIf(fn () => $request->input('business_kind') !== 'restaurant'), 'nullable', 'array'],
            'categories.*' => ['in:'.implode(',', array_keys(Categories::all()))],
            'phone' => ['required', 'string', 'max:30'],
            'address' => ['required', 'string', 'max:300'],
            'license_number' => ['nullable', 'string', 'max:80'],
            'license' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'second_license' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $first = $data['license'];
        $second = $data['second_license'] ?? null;

        TradeApplication::create([
            'user_id' => $user->id, 'business_name' => $data['business_name'], 'business_kind' => $data['business_kind'],
            'categories' => $data['business_kind'] === 'restaurant' ? [] : array_values($data['categories'] ?? ['medicine']), 'phone' => $data['phone'], 'address' => $data['address'],
            'license_number' => $data['license_number'] ?? null,
            'license_path' => app(ImageCompressor::class)->store($first, 'licenses', 2000), 'license_name' => mb_substr($first->getClientOriginalName(), 0, 150),
            'second_path' => $second ? app(ImageCompressor::class)->store($second, 'licenses', 2000) : null, 'second_name' => $second ? mb_substr($second->getClientOriginalName(), 0, 150) : null,
            'note' => $data['note'] ?? null,
        ]);

        return back()->with('status', __('Application sent. We will check your licence and reply here.'));
    }
}
