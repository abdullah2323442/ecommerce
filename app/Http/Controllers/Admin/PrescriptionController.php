<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Prescription;
use App\Models\Setting;
use App\Services\Audit\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Prescriptions sent by shops: staff and admin only, never a public file (spec 4.4.12). */
class PrescriptionController extends Controller
{
    public function __construct(private ActivityLogger $activity) {}

    public function index(): Response
    {
        $this->authorize('handle-orders');
        $alert = (int) Setting::read('prescriptions.alert_minutes', 30);

        return Inertia::render('admin/prescriptions', [
            'unseen' => Prescription::whereNull('seen_at')->count(),
            'items' => Prescription::with('user:id,name,phone')->orderByRaw('seen_at is not null')->latest('id')->limit(60)->get()
                ->map(fn (Prescription $p) => [
                    'id' => $p->id, 'shop' => $p->user?->name, 'phone' => $p->user?->phone, 'note' => $p->note, 'name' => $p->original_name,
                    'seen' => $p->seen_at !== null, 'minutes' => (int) $p->created_at->diffInMinutes(now()),
                    'late' => $p->seen_at === null && $p->created_at->diffInMinutes(now()) >= $alert,
                ]),
        ]);
    }

    public function file(Prescription $prescription): StreamedResponse
    {
        $this->authorize('handle-orders');
        abort_unless(Storage::disk('local')->exists($prescription->path), 404);
        $this->activity->record('prescription.viewed', $prescription, [], request()->user());

        return Storage::disk('local')->response($prescription->path, $prescription->original_name ?? 'prescription', ['Cache-Control' => 'private, no-store']);
    }

    public function seen(Request $request, Prescription $prescription): RedirectResponse
    {
        $this->authorize('handle-orders');
        $prescription->update(['seen_at' => now(), 'seen_by' => $request->user()->id]);

        return back();
    }
}
