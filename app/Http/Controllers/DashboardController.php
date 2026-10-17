<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Admin\CourierController;
use App\Models\ActivityLog;
use App\Models\BlockedIp;
use App\Models\Courier;
use App\Models\LoginEvent;
use App\Models\Medicine;
use App\Models\ProductRequest;
use App\Models\User;
use App\Services\Backup\BackupService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The admin dashboard is a work list, not a wall of charts: it opens with what needs attention,
 * then shows the numbers behind it. Everyone else gets a plain account page.
 */
class DashboardController extends Controller
{
    private const CUSTOMER_TYPES = ['public', 'paikari', 'wholesaler'];

    private const ACCOUNT_LABELS = [
        'public' => 'Customer account',
        'paikari' => 'Paikari customer',
        'wholesaler' => 'Wholesale customer',
        'mpo' => 'MPO',
        'food_vendor' => 'Food vendor',
        'craftsman' => 'Craftsman',
        'staff' => 'Staff',
        'admin' => 'Admin',
    ];

    public function __invoke(Request $request, BackupService $backups): Response
    {
        $user = $request->user();

        if (! $user->can('manage-customers')) {
            return Inertia::render('account', [
                'account' => [
                    'name' => $user->name,
                    // Only this viewer's own label, translated here. The browser never gets the list of
                    // account types, so it cannot learn which other sections exist (G1).
                    'type_label' => __(self::ACCOUNT_LABELS[$user->account_type->value] ?? 'Customer'),
                    'tier' => $user->tier?->name,
                ],
            ]);
        }

        $backupAt = $backups->latestAt();
        $stats = $this->stats($backupAt?->toIso8601String());

        return Inertia::render('dashboard', [
            'alerts' => $this->alerts($stats, $backups),
            'stats' => $stats,
            'recent' => $this->recentActivity(),
            'commerce' => app(\App\Services\Admin\CommerceStats::class)->all(),
        ]);
    }

    /** @return array<string, mixed> */
    private function stats(?string $backupAt): array
    {
        $byType = User::query()
            ->whereIn('account_type', self::CUSTOMER_TYPES)
            ->selectRaw('account_type, count(*) as total')
            ->groupBy('account_type')
            ->pluck('total', 'account_type');

        $medicines = Medicine::query()->selectRaw(
            'count(*) as total,
             sum(case when image_path is not null then 1 else 0 end) as local_image,
             sum(case when image_path is null and image_source_url is not null then 1 else 0 end) as remote_image,
             sum(case when prescription_required = 1 or is_cold_chain = 1 or is_controlled = 1 then 1 else 0 end) as flagged'
        )->first();

        return [
            'customers' => [
                'total' => (int) $byType->sum(),
                'by_type' => collect(self::CUSTOMER_TYPES)->mapWithKeys(fn ($t) => [$t => (int) ($byType[$t] ?? 0)]),
                'suspended' => User::query()->whereIn('account_type', self::CUSTOMER_TYPES)->whereNotNull('suspended_at')->count(),
                'without_tier' => User::query()->whereIn('account_type', ['paikari', 'wholesaler'])->whereNull('tier_id')->count(),
            ],
            'medicines' => [
                'total' => (int) $medicines->total,
                'local_image' => (int) $medicines->local_image,
                'remote_image' => (int) $medicines->remote_image,
                'no_image' => (int) $medicines->total - (int) $medicines->local_image - (int) $medicines->remote_image,
                'flagged' => (int) $medicines->flagged,
            ],
            'security' => [
                'suspicious_7d' => LoginEvent::query()->where('is_suspicious', true)->where('created_at', '>=', now()->subDays(7))->count(),
                'failed_24h' => LoginEvent::query()->where('outcome', 'failed')->where('created_at', '>=', now()->subDay())->count(),
                'blocked_ips' => BlockedIp::query()->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->count(),
            ],
            'requests' => ['unfound' => ProductRequest::query()->distinct()->count('normalized')],
            'backup' => ['latest_at' => $backupAt, 'disk' => config('backup.disk')],
        ];
    }

    /**
     * Things the admin should act on, most serious first. Each one says what is wrong and why it matters.
     * `title` and `detail` are English templates with {placeholders} filled from `params`; the browser
     * translates the template and then fills it in, so the same alert reads right in Bangla.
     *
     * @param  array<string, mixed>  $stats
     * @return list<array{level: string, title: string, detail: string, href: ?string, params: array<string, mixed>}>
     */
    private function alerts(array $stats, BackupService $backups): array
    {
        $alert = fn (string $level, string $title, string $detail, ?string $href = null, array $params = []) => compact('level', 'title', 'detail', 'href', 'params');
        $alerts = [];

        if ($stats['backup']['latest_at'] === null) {
            $alerts[] = $alert('warning', 'No backup has been made yet', 'Run php artisan backup:run, and check the nightly schedule is running.');
        } elseif ($backups->isStale()) {
            $alerts[] = $alert('warning', 'The last backup is more than 36 hours old', 'The nightly backup is not running. Check the scheduler on the server.');
        }

        if (config('backup.disk') === 'local') {
            $alerts[] = $alert('warning', 'Backups are stored on this server', 'If the server fails, the backups go with it. Point BACKUP_DISK at storage on a different machine.');
        }

        if (config('app.debug') && app()->isProduction()) {
            $alerts[] = $alert('warning', 'Debug mode is on in production', 'Error pages can reveal passwords and code. Set APP_DEBUG=false.');
        }

        $suspicious = $stats['security']['suspicious_7d'];
        if ($suspicious > 0) {
            $alerts[] = $alert('warning', $suspicious === 1 ? '{n} suspicious login in the last 7 days' : '{n} suspicious logins in the last 7 days', 'Logins from a new device when the account already uses its limit. Open the customer to see details.', '/admin/customers', ['n' => $suspicious]);
        }

        $noTier = $stats['customers']['without_tier'];
        if ($noTier > 0) {
            $alerts[] = $alert('info', $noTier === 1 ? '{n} paikari or wholesale customer has no tier' : '{n} paikari and wholesale customers have no tier', 'They pay full price until a tier is set.', '/admin/customers', ['n' => $noTier]);
        }

        if ($stats['medicines']['flagged'] === 0 && $stats['medicines']['total'] > 0) {
            $alerts[] = $alert('info', 'No medicine is marked prescription, cold-chain or controlled', 'The imported data has none of these flags. Controlled medicines need them for the legal sale register.');
        }

        $noPhoto = $stats['medicines']['remote_image'] + $stats['medicines']['no_image'];
        if ($noPhoto > 0) {
            $alerts[] = $alert('info', '{n} medicines have no local photo', 'They show a photo from another website, or none. That is slower and can disappear.', null, ['n' => $noPhoto]);
        }

        // Courier rates go out of date without anyone noticing (spec ch 20): ask every three months.
        if (CourierController::isStale()) {
            $alerts[] = $alert('warning', 'Are the courier rates still correct?', 'Nobody has checked them for three months. A wrong rate loses money on every delivery without anyone noticing.', '/admin/couriers');
        }

        if (Courier::where('is_active', true)->exists()) {
            $noWeight = Medicine::where('is_active', true)->whereNull('weight_g')->count();
            if ($noWeight > 0) {
                $alerts[] = $alert('info', '{n} medicines have no weight', 'Without a weight on every product in an order, couriers cannot be priced and the flat area charge is used.', '/admin/weights?missing=1', ['n' => $noWeight]);
            }
        }

        $unfound = $stats['requests']['unfound'];
        if ($unfound > 0) {
            $alerts[] = $alert('info', $unfound === 1 ? '{n} product visitors could not find' : '{n} products visitors could not find', 'Searches that found nothing, where the visitor asked for it. See which are asked for most.', '/admin/requests', ['n' => $unfound]);
        }

        return $alerts;
    }

    /** @return list<array{id: int, action: string, actor: ?string, at: string}> */
    private function recentActivity(): array
    {
        return ActivityLog::query()
            ->leftJoin('users', 'users.id', '=', 'activity_logs.actor_id')
            ->latest('activity_logs.created_at')
            ->limit(8)
            ->get(['activity_logs.id', 'activity_logs.action', 'activity_logs.created_at', 'users.name as actor'])
            ->map(fn ($row) => [
                'id' => $row->id,
                'action' => $row->action,
                'actor' => $row->actor,
                'at' => $row->created_at->toIso8601String(),
            ])
            ->all();
    }
}
