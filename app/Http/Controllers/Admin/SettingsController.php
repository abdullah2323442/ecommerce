<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Audit\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** The owner's rules in one place (spec ch 25): closed days, night hours, discount limits, return window and more. */
class SettingsController extends Controller
{
    public function __construct(private ActivityLogger $activity) {}

    public function index(): Response
    {
        $this->authorize('manage-accounts');

        return Inertia::render('admin/settings', [
            'groups' => collect((array) config('shop_settings'))->map(fn (array $group, string $key) => [
                'key' => $key, 'title' => $group['title'], 'hint' => $group['hint'] ?? null,
                'items' => collect($group['items'])->map(fn (array $item, string $name) => [
                    'key' => $name, 'type' => $item['type'], 'label' => $item['label'], 'min' => $item['min'] ?? null, 'max' => $item['max'] ?? null, 'step' => $item['step'] ?? null, 'options' => $item['options'] ?? null,
                    'value' => Setting::read($name, $item['default'] ?? null) ?? ($item['type'] === 'text' ? config('app.name') : null),
                ])->values(),
            ])->values(),
        ]);
    }

    public function update(Request $request, string $group): RedirectResponse
    {
        $this->authorize('manage-accounts');
        $items = config("shop_settings.$group.items");
        abort_unless(is_array($items), 404);

        $rules = [];
        foreach ($items as $key => $item) {
            $field = str_replace('.', '_', $key);
            $rules[$field] = match ($item['type']) {
                'number' => ['required', 'numeric', 'min:'.$item['min'], 'max:'.$item['max']],
                'text' => [($item['optional'] ?? false) ? 'nullable' : 'required', 'string', 'max:'.$item['max']],
                'select' => ['required', Rule::in(array_keys($item['options']))],
                'toggle' => ['required', 'boolean'],
                'days' => ['present', 'array'],
                'dates' => ['present', 'array', 'max:400'],
            };
            if ($item['type'] === 'days') {
                $rules[$field.'.*'] = ['integer', 'between:0,6'];
            }
            if ($item['type'] === 'dates') {
                $rules[$field.'.*'] = ['date_format:Y-m-d'];
            }
        }
        $data = $request->validate($rules);

        $changed = [];
        foreach ($items as $key => $item) {
            $value = $data[str_replace('.', '_', $key)] ?? null;
            $value = match ($item['type']) {
                'number' => $value + 0,
                'toggle' => (bool) $value,
                'days' => collect($value)->map(fn ($d) => (int) $d)->unique()->sort()->values()->all(),
                'dates' => collect($value)->unique()->sort()->values()->all(),
                default => trim((string) ($value ?? '')),
            };

            $before = Setting::read($key, $item['default'] ?? null);
            if ($before !== $value && json_encode($before) !== json_encode($value)) {
                Setting::write($key, $value);
                $changed[$key] = ['before' => $before, 'after' => $value];
            }
        }

        if ($changed) {
            $this->activity->record('settings.changed', null, $changed, $request->user());
        }

        return back()->with('status', __('Settings saved.'));
    }
}
