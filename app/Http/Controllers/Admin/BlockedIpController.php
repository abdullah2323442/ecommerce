<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BlockedIp;
use App\Services\Audit\ActivityLogger;
use App\Services\Security\IpBlocklist;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** The owner blocks an address that is attacking or abusing the site (spec ch 26). A block can end by itself after some days. */
class BlockedIpController extends Controller
{
    public function __construct(private ActivityLogger $activity, private IpBlocklist $blocklist) {}

    public function index(): Response
    {
        $this->authorize('manage-accounts');

        return Inertia::render('admin/blocked-ips', [
            'blocks' => BlockedIp::query()->with('blocker:id,name')->latest('id')->get()->map(fn (BlockedIp $b) => [
                'id' => $b->id, 'ip' => $b->ip, 'reason' => $b->reason, 'by' => $b->blocker?->name,
                'since' => $b->created_at?->toIso8601String(), 'until' => $b->expires_at?->toIso8601String(), 'active' => $b->isActive(),
            ])->values(),
            'yourIp' => request()->ip(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('manage-accounts');

        $data = $request->validate([
            'ip' => ['required', 'ip'],
            'reason' => ['nullable', 'string', 'max:255'],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        // Blocking the address you are using would lock the owner out of the site that unblocks it.
        if ($data['ip'] === $request->ip()) {
            throw ValidationException::withMessages(['ip' => __('That is the address you are using now. Blocking it would lock you out.')]);
        }

        BlockedIp::updateOrCreate(['ip' => $data['ip']], [
            'reason' => $data['reason'] ?? null, 'blocked_by' => $request->user()->id,
            'expires_at' => isset($data['days']) ? now()->addDays((int) $data['days']) : null,
        ]);
        $this->blocklist->forget();
        $this->activity->record('security.ip_blocked', null, ['ip' => $data['ip'], 'days' => $data['days'] ?? null, 'reason' => $data['reason'] ?? null], $request->user());

        return back()->with('status', __('Address blocked.'));
    }

    public function destroy(Request $request, BlockedIp $blockedIp): RedirectResponse
    {
        $this->authorize('manage-accounts');

        $ip = $blockedIp->ip;
        $blockedIp->delete();
        $this->blocklist->forget();
        $this->activity->record('security.ip_unblocked', null, ['ip' => $ip], $request->user());

        return back()->with('status', __('Address unblocked.'));
    }
}
