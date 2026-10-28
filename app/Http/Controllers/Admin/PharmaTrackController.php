<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Models\SyncEvent;
use App\Services\Audit\ActivityLogger;
use App\Services\Integrations\Integrations;
use App\Services\Sync\PharmaTrack;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The PharmaTrack screen (spec item 612): is the link on, what passed between the two programs, what failed, and the password PharmaTrack needs. */
class PharmaTrackController extends Controller
{
    public function __construct(private PharmaTrack $sync, private Integrations $integrations, private ActivityLogger $activity) {}

    public function index(Request $request): Response
    {
        $this->authorize('manage-accounts');
        $row = Integration::where('key', PharmaTrack::KEY)->first();

        return Inertia::render('admin/pharmatrack', [
            'link' => [
                'on' => (bool) $row?->enabled, 'live' => $row?->mode === 'live', 'has_token' => ! empty(($row?->credentials ?? [])['inbound_token']), 'has_address' => ! empty(($row?->settings ?? [])['base_url']),
                'pushes' => (($row?->settings ?? [])['push_online_sales'] ?? 'no') === 'yes', 'pushes_missed' => (($row?->settings ?? [])['push_missed'] ?? 'yes') === 'yes',
            ],
            'base' => rtrim((string) config('app.url'), '/').'/webhooks/pharmatrack',
            'counts' => $this->sync->counts(),
            'events' => SyncEvent::latest('id')->limit(60)->get()->map(fn (SyncEvent $e) => [
                'id' => $e->id, 'direction' => $e->direction, 'kind' => $e->kind, 'ref' => $e->ref, 'status' => $e->status, 'attempts' => $e->attempts, 'summary' => $e->summaryText(), 'error' => $e->errorText(), 'at' => $e->updated_at->toIso8601String(),
            ])->all(),
            'token' => $request->session()->get('pharmatrack_token'),
        ]);
    }

    /** A new password for PharmaTrack. It is shown once here and never again; the old one stops working at once. */
    public function newToken(Request $request): RedirectResponse
    {
        $this->authorize('manage-accounts');
        $row = Integration::where('key', PharmaTrack::KEY)->first();

        if ($row === null) {
            return back()->withErrors(['link' => __('Save and switch on the connection in Connections first.')]);
        }

        $token = PharmaTrack::newToken();
        $row->update(['credentials' => array_merge((array) $row->credentials, ['inbound_token' => $token])]);
        $this->integrations->forget();
        $this->activity->record('pharmatrack.token_made', null, [], $request->user());

        return back()->with('pharmatrack_token', $token)->with('status', __('A new password is made. Copy it now: it is not shown again.'));
    }

    public function retry(Request $request): RedirectResponse
    {
        $this->authorize('manage-accounts');
        $n = $this->sync->retryFailed();

        return back()->with('status', $n > 0 ? __('{n} messages are being sent again.', ['n' => $n]) : __('Nothing failed.'));
    }
}
