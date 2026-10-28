<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SmsCampaignRequest;
use App\Models\SmsCampaign;
use App\Models\Tier;
use App\Services\Integrations\Integrations;
use App\Services\Marketing\BulkSms;
use App\Services\Stock\StockException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Bulk texts (spec ch 27): write one message, choose who it is for, test it on your own phone, then send. */
class SmsController extends Controller
{
    public function __construct(private BulkSms $bulk, private Integrations $integrations) {}

    public function index(Request $request): Response
    {
        $this->authorize('manage-customers');

        return Inertia::render('admin/sms/index', [
            'campaigns' => SmsCampaign::latest('id')->limit(50)->get()->map(fn (SmsCampaign $c) => $this->row($c))->all(),
            'tiers' => Tier::orderBy('name')->get(['id', 'name'])->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->all(),
            'service' => $this->service(),
            'limit' => ['daily' => $this->bulk->dailyLimit(), 'today' => $this->bulk->sentToday()],
            'maxBody' => BulkSms::MAX_BODY,
            'footer' => $this->bulk->footer(),
        ]);
    }

    /** How many people, and how many text segments each would cost, while the owner is still choosing. */
    public function audience(Request $request): JsonResponse
    {
        $this->authorize('manage-customers');
        $filters = $this->bulk->filters($request->query());
        $body = (string) $request->query('body', '');

        return response()->json([
            'count' => $this->bulk->people($filters)->count(), 'label' => $this->bulk->label($filters),
            'segments' => $this->bulk->segments($body.$this->bulk->footer()), 'letters' => mb_strlen($body.$this->bulk->footer()),
        ]);
    }

    public function store(SmsCampaignRequest $request): RedirectResponse
    {
        try {
            $campaign = $this->bulk->create($request->validated(), $request->user());
        } catch (StockException $e) {
            return back()->withErrors(['campaign' => __($e->getMessage())])->withInput();
        }

        return to_route('admin.sms.show', $campaign)->with('status', __('The campaign is saved. Send a test to your own phone, then send it.'));
    }

    public function show(Request $request, SmsCampaign $campaign): Response
    {
        $this->authorize('manage-customers');

        return Inertia::render('admin/sms/show', [
            'campaign' => $this->row($campaign) + ['body' => $campaign->body, 'audience_label' => $campaign->audience_label],
            'people' => $campaign->people()->orderBy('id')->limit(100)->get(['phone', 'status', 'note', 'sent_at'])->map(fn ($p) => ['phone' => '*******'.substr($p->phone, -4), 'status' => $p->status, 'note' => $p->note ? __($p->note) : null, 'at' => $p->sent_at?->toIso8601String()])->all(),
            'segments' => $this->bulk->segments($campaign->body.$this->bulk->footer()),
            'service' => $this->service(),
            'footer' => $this->bulk->footer(),
        ]);
    }

    public function test(Request $request, SmsCampaign $campaign): RedirectResponse
    {
        $this->authorize('manage-customers');
        $data = $request->validate(['phone' => ['required', 'string', 'max:20']]);

        try {
            $this->bulk->test($campaign, preg_replace('/\D+/', '', $data['phone']) ?? '', $request->user());
        } catch (StockException $e) {
            return back()->withErrors(['test' => __($e->getMessage())]);
        }

        return back()->with('status', __('The test went to your phone.'));
    }

    public function start(Request $request, SmsCampaign $campaign): RedirectResponse
    {
        $this->authorize('manage-customers');

        try {
            $this->bulk->start($campaign, $request->user());
        } catch (StockException $e) {
            return back()->withErrors(['start' => __($e->getMessage())]);
        }

        return back()->with('status', __('The texts are going out.'));
    }

    public function cancel(Request $request, SmsCampaign $campaign): RedirectResponse
    {
        $this->authorize('manage-customers');

        try {
            $this->bulk->cancel($campaign, $request->user());
        } catch (StockException $e) {
            return back()->withErrors(['start' => __($e->getMessage())]);
        }

        return back()->with('status', __('The campaign is stopped. What had not gone out will not be sent.'));
    }

    /** @return array<string, mixed> */
    private function row(SmsCampaign $c): array
    {
        return [
            'id' => $c->id, 'name' => $c->name, 'status' => $c->status, 'recipients' => $c->recipients, 'audience' => $c->audience_label, 'at' => $c->created_at->toIso8601String(),
            'progress' => $this->bulk->progress($c),
        ];
    }

    /** Is a text service switched on, and is it live (a test-mode service only writes to the log)? @return array{on: bool, live: bool} */
    private function service(): array
    {
        $on = $this->integrations->activeOfType('sms');

        return ['on' => $on !== null, 'live' => $on !== null && ($on[1]['mode'] ?? 'test') === 'live'];
    }
}
