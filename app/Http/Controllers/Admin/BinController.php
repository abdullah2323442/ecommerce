<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeletedItem;
use App\Services\Audit\ActivityLogger;
use App\Services\Audit\Bin;
use App\Services\Stock\StockException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** The "Deleted" bin (spec ch 25): what was removed in the last 30 days, with a way to put it back. Owner only. */
class BinController extends Controller
{
    public function __construct(private Bin $bin, private ActivityLogger $activity) {}

    public function index(): Response
    {
        $this->authorize('manage-accounts');

        return Inertia::render('admin/deleted', [
            'days' => $this->bin->days(),
            'items' => DeletedItem::with('deletedBy:id,name')->latest('id')->limit(200)->get()->map(fn (DeletedItem $i) => [
                'id' => $i->id, 'type' => $i->type, 'label' => $i->label, 'by' => $i->deletedBy?->name, 'at' => $i->created_at->toIso8601String(),
                'days_left' => max(0, $this->bin->days() - (int) $i->created_at->diffInDays(now())),
            ]),
        ]);
    }

    public function restore(Request $request, DeletedItem $item): RedirectResponse
    {
        $this->authorize('manage-accounts');

        try {
            $this->bin->restore($item);
        } catch (StockException $e) {
            throw ValidationException::withMessages(['item' => __($e->getMessage())]);
        }

        $this->activity->record('bin.restored', null, ['type' => $item->type, 'label' => $item->label], $request->user());

        return back()->with('status', __('Put back.'));
    }
}
