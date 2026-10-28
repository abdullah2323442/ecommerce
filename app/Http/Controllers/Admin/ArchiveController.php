<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ArchivedRecord;
use App\Services\Archive\OldRecords;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The archive of old records (spec ch 28, note 11): what daily work no longer reads, kept whole and searchable. Same people as the activity log. */
class ArchiveController extends Controller
{
    public function __construct(private OldRecords $archive) {}

    public function index(Request $request): Response
    {
        $this->authorize('manage-accounts');

        $rows = $this->archive->search($request->query('q'), $request->query('source'), $request->query('from'), $request->query('to'));

        return Inertia::render('admin/archive', [
            'rows' => $rows->through(fn (ArchivedRecord $r) => ['id' => $r->id, 'source' => $r->source, 'at' => $r->occurred_at->toIso8601String(), 'summary' => $r->summary, 'details' => $r->payload]),
            'counts' => $this->archive->counts(),
            'months' => $this->archive->months(),
            'filters' => ['q' => (string) $request->query('q', ''), 'source' => in_array($request->query('source'), OldRecords::SOURCES, true) ? $request->query('source') : null, 'from' => $request->query('from'), 'to' => $request->query('to')],
        ]);
    }

    /** Move what is old now, instead of waiting for the night. */
    public function run(Request $request): RedirectResponse
    {
        $this->authorize('manage-accounts');
        $moved = $this->archive->run();

        return back()->with('status', $this->archive->months() === 0
            ? __('Archiving is switched off. Set the number of months in Settings.')
            : __('{n} old records moved to the archive.', ['n' => array_sum($moved)]));
    }
}
