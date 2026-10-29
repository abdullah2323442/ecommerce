<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Media\ImageCompressor;
use App\Services\Sales\TierUpgrade;
use App\Services\Sales\TradeTerms;
use App\Support\Categories;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The bulk buyer's own page (spec ch 14): what they bought, how their spending went month by month, their grade
 * and their terms. The grade and the trend only use months that are finished, so nothing here counts live and
 * nobody can climb a grade by buying more at the end of a month.
 */
class WholesaleController extends Controller
{
    private const MONTHS = 6;

    public function __construct(private TradeTerms $terms, private TierUpgrade $upgrade) {}

    public function account(Request $request): Response
    {
        $user = $request->user();
        if ($user->account_type !== AccountType::Wholesaler) {
            throw new AuthorizationException;
        }

        $first = now()->startOfMonth()->subMonths(self::MONTHS);
        $final = Invoice::where('customer_id', $user->id)->where('status', 'final')->where('finalised_at', '>=', $first)->get(['id', 'total', 'finalised_at']);

        $trend = collect(range(self::MONTHS, 1))->map(function (int $back) use ($final) {
            $month = now()->startOfMonth()->subMonths($back);
            $rows = $final->filter(fn ($i) => $i->finalised_at->isSameMonth($month) && $i->finalised_at->isSameYear($month));

            return ['month' => $month->format('Y-m'), 'total' => round((float) $rows->sum('total'), 2), 'orders' => $rows->count()];
        })->values();

        return Inertia::render('wholesale/account', [
            'grade' => $user->tier?->name,
            // Progress towards the next grade only appears in the month-end window (spec ch 14 note, ch 07).
            'tierTask' => $this->upgrade->inWindow() ? $this->upgrade->task($user->load('tier')) : null,
            'trend' => $trend,
            'history' => Invoice::where('customer_id', $user->id)->where('status', 'final')->latest('finalised_at')->limit(20)->get(['id', 'number', 'total', 'finalised_at', 'payment_terms'])
                ->map(fn (Invoice $i) => ['id' => $i->id, 'number' => $i->number, 'total' => (float) $i->total, 'when' => $i->finalised_at?->toIso8601String(), 'terms' => $i->payment_terms]),
            'terms' => [
                'moq' => $this->terms->moq($user),
                'payments' => $this->terms->payments($user),
                'categories' => collect((array) $user->categories)->map(fn ($k) => Categories::label($k))->filter()->values(),
            ],
            'business' => $user->business_name ?? $user->name,
            'logo' => $this->logoUrl($user),
        ]);
    }

    /** The shop makes the dashboard its own: its name and logo, shown at the top of its pages (spec ch 14, white-label). */
    public function branding(Request $request, ImageCompressor $images): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:150'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_logo' => ['nullable', 'boolean'],
        ]);

        $path = $user->logo_path;
        if ($request->boolean('remove_logo') || $request->hasFile('logo')) {
            if ($path) {
                Storage::delete($path);
            }
            $path = $request->hasFile('logo') ? $images->store($request->file('logo'), 'logos', 400) : null;
        }

        $user->forceFill(['business_name' => $data['business_name'], 'logo_path' => $path])->save();

        return back()->with('status', __('Saved.'));
    }

    /** The shop's own logo, for the shop only. */
    public function logo(Request $request): StreamedResponse
    {
        $path = $request->user()->logo_path;
        abort_unless($path && Storage::exists($path), 404);

        return Storage::response($path, null, ['Cache-Control' => 'private, max-age=3600']);
    }

    private function logoUrl(User $user): ?string
    {
        return $user->logo_path ? '/wholesale/account/logo?v='.substr(md5($user->logo_path), 0, 8) : null;
    }
}
