<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Audit\ActivityLogger;
use App\Services\Messages\Messenger;
use App\Services\Push\Notifications;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Which message goes by which channel (spec ch 23: controlled from the admin panel, because SMS costs money). */
class MessagesController extends Controller
{
    /** Push can be chosen once the owner has switched it on in Connections. */
    private const AVAILABLE = ['inbox', 'email', 'sms'];

    public function __construct(private Messenger $messenger, private ActivityLogger $activity) {}

    public function index(): Response
    {
        $this->authorize('manage-accounts');

        return Inertia::render('admin/messages', [
            'kinds' => collect((array) config('messages.kinds'))->map(fn (array $k, string $key) => [
                'key' => $key, 'label' => $k['label'], 'importance' => $k['importance'], 'channels' => $this->messenger->channelsFor($key),
            ])->values(),
            'channels' => config('messages.channels'),
            'available' => $this->available(),
        ]);
    }

    /** @return list<string> */
    private function available(): array
    {
        return app(Notifications::class)->enabled() ? [...self::AVAILABLE, 'push'] : self::AVAILABLE;
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorize('manage-accounts');
        $kinds = array_keys((array) config('messages.kinds'));

        $data = $request->validate([
            'channels' => ['required', 'array'],
            'channels.*' => ['array'],
            'channels.*.*' => [Rule::in($this->available())],
        ]);

        $before = (array) Setting::read('messages.channels', []);
        $saved = [];
        foreach ($kinds as $kind) {
            // The inbox is always on; the rest are the owner's choice.
            $saved[$kind] = array_values(array_unique(['inbox', ...array_intersect((array) ($data["channels"][$kind] ?? []), $this->available())]));
        }

        Setting::write('messages.channels', $saved);
        $this->activity->record('messages.channels', null, ['before' => $before, 'after' => $saved], $request->user());

        return back()->with('status', __('Saved.'));
    }
}
