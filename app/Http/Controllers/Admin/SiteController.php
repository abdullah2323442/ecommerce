<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Site\Site;
use App\Services\Stock\StockException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Admin > Site design: the owner changes the name, logo, contact details, home page, footer and notice bar without a developer. */
class SiteController extends Controller
{
    public function __construct(private Site $site) {}

    public function index(): Response
    {
        $this->authorize('manage-accounts');
        $all = $this->site->all();

        return Inertia::render('admin/site', [
            'groups' => $all,
            'images' => ['logo' => ! empty($all['brand']['logo']), 'favicon' => ! empty($all['brand']['favicon'])],
            'preview' => $this->site->forViewer()['brand'],
        ]);
    }

    public function save(Request $request, string $group): RedirectResponse
    {
        $this->authorize('manage-accounts');
        abort_unless(in_array($group, Site::GROUPS, true), 404);

        try {
            $this->site->save($group, $request->except(['_token', '_method']), $request->user());
        } catch (StockException $e) {
            throw ValidationException::withMessages(['site' => __($e->getMessage())]);
        }

        return back()->with('status', __('Saved. The site shows it now.'));
    }

    public function image(Request $request, string $which): RedirectResponse
    {
        $this->authorize('manage-accounts');
        abort_unless(in_array($which, ['logo', 'favicon'], true), 404);
        $request->validate(['image' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048']]);

        try {
            $this->site->putImage($which, $request->file('image'), $request->user());
        } catch (StockException $e) {
            throw ValidationException::withMessages(['image' => __($e->getMessage())]);
        }

        return back()->with('status', __('Saved. The site shows it now.'));
    }

    public function removeImage(Request $request, string $which): RedirectResponse
    {
        $this->authorize('manage-accounts');
        abort_unless(in_array($which, ['logo', 'favicon'], true), 404);
        $this->site->removeImage($which, $request->user());

        return back()->with('status', __('Removed.'));
    }
}
