<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Integrations\Integrations;
use App\Services\Stock\StockException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Admin > Connections: keys and switches for the outside services. Secrets go in encrypted and never come back to the page. */
class IntegrationController extends Controller
{
    public function __construct(private Integrations $integrations) {}

    public function index(): Response
    {
        $this->authorize('manage-accounts');

        return Inertia::render('admin/connections', ['types' => $this->integrations->overview(), 'redirectBase' => rtrim((string) config('app.url'), '/')]);
    }

    public function save(Request $request, string $key): RedirectResponse
    {
        $this->authorize('manage-accounts');
        $data = $request->validate([
            'fields' => ['nullable', 'array'], 'fields.*' => ['nullable', 'string', 'max:4000'], 'clear' => ['nullable', 'array'], 'clear.*' => ['string', 'max:60'],
            'enabled' => ['required', 'boolean'], 'mode' => ['required', 'in:test,live'],
        ]);

        try {
            $this->integrations->save($key, (array) ($data['fields'] ?? []), (bool) $data['enabled'], $data['mode'], (array) ($data['clear'] ?? []), $request->user());
        } catch (StockException $e) {
            throw ValidationException::withMessages(['connection' => __($e->getMessage())]);
        }

        return back()->with('status', __('Saved.'));
    }

    public function test(Request $request, string $key): RedirectResponse
    {
        $this->authorize('manage-accounts');
        $data = $request->validate(['to' => ['nullable', 'string', 'max:20']]);

        try {
            $result = $this->integrations->test($key, $data, $request->user());
        } catch (StockException $e) {
            throw ValidationException::withMessages(['connection' => __($e->getMessage())]);
        }

        return back()->with('status', $result['ok'] ? __('It works.').' '.__($result['note']) : __('It did not work.').' '.__($result['note']));
    }
}
