<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Models\MeasurementProfile;
use App\Services\Custom\MeasurementProfiles;
use App\Services\Stock\StockException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** A buyer's saved measurements, under a name each (spec ch 16.3). */
class MeasurementProfileController extends Controller
{
    public function __construct(private MeasurementProfiles $profiles) {}

    public function index(Request $request): Response
    {
        $this->buyer($request);

        return Inertia::render('custom/profiles', [
            'profiles' => MeasurementProfile::where('user_id', $request->user()->id)->orderBy('name')->get()->map(fn ($p) => $this->profiles->present($p)),
            'fields' => config('custom.fields'), 'units' => config('custom.units'), 'staleMonths' => $this->profiles->staleMonths(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->buyer($request);
        $data = $this->data($request);

        return $this->act(fn () => $this->profiles->save($request->user(), $data['name'], $data['kind'], $data['unit'], $data['values']), 'Saved.');
    }

    public function update(Request $request, MeasurementProfile $profile): RedirectResponse
    {
        $this->own($request, $profile);
        $data = $this->data($request);

        return $this->act(fn () => $this->profiles->save($request->user(), $data['name'], $data['kind'], $data['unit'], $data['values'], $profile), 'Saved.');
    }

    public function stillCorrect(Request $request, MeasurementProfile $profile): RedirectResponse
    {
        $this->own($request, $profile);
        $this->profiles->stillCorrect($profile);

        return back()->with('status', __('Thank you. We have noted today.'));
    }

    public function destroy(Request $request, MeasurementProfile $profile): RedirectResponse
    {
        $this->own($request, $profile);
        $profile->delete();

        return back()->with('status', __('Removed.'));
    }

    private function data(Request $request): array
    {
        return $request->validate(['name' => ['required', 'string', 'max:60'], 'kind' => ['required', 'in:men,women,wood'], 'unit' => ['required', 'in:inch,foot,cm'], 'values' => ['required', 'array']]);
    }

    private function act(callable $do, string $done): RedirectResponse
    {
        try {
            $do();
        } catch (StockException $e) {
            return back()->withErrors(['profile' => __($e->getMessage())]);
        }

        return back()->with('status', __($done));
    }

    private function own(Request $request, MeasurementProfile $profile): void
    {
        $this->buyer($request);

        if ($profile->user_id !== $request->user()->id) {
            throw new AuthorizationException;
        }
    }

    private function buyer(Request $request): void
    {
        if (! in_array($request->user()?->account_type, [AccountType::Public, AccountType::Paikari, AccountType::Wholesaler], true)) {
            throw new AuthorizationException;
        }
    }
}
