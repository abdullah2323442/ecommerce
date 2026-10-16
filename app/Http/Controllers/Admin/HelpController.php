<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\HelpComment;
use App\Models\HelpPost;
use App\Services\Audit\ActivityLogger;
use App\Services\Help\HelpBoard;
use App\Services\Stock\StockException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** We read every Help post before anyone else sees it, and look at the ones people report (spec ch 19). Owner and sub-admin. */
class HelpController extends Controller
{
    public function __construct(private HelpBoard $board, private ActivityLogger $activity) {}

    public function index(): Response
    {
        $this->authorize('manage-customers');

        $row = fn (HelpPost $p) => [
            'id' => $p->id, 'category' => $p->category, 'title' => $p->title, 'body' => $p->body, 'contact' => $p->contact, 'status' => $p->status,
            'hidden' => $p->hidden, 'by' => $p->author?->name, 'at' => $p->created_at->toIso8601String(), 'reports' => $p->reports_count ?? 0,
            'comments' => $p->comments()->latest('id')->limit(30)->get()->map(fn (HelpComment $c) => ['id' => $c->id, 'body' => $c->body, 'hidden' => $c->hidden, 'by' => $c->author?->name]),
        ];

        return Inertia::render('admin/help', [
            'pending' => HelpPost::where('status', 'pending')->with('author:id,name')->withCount('reports')->oldest('id')->get()->map($row),
            'reported' => HelpPost::where('status', 'open')->whereHas('reports')->with('author:id,name')->withCount('reports')->latest('id')->get()->map($row),
            'open' => HelpPost::where('status', 'open')->with('author:id,name')->withCount('reports')->latest('id')->limit(30)->get()->map($row),
        ]);
    }

    public function approve(Request $request, HelpPost $post): RedirectResponse
    {
        $this->authorize('manage-customers');

        return $this->attempt(fn () => $this->board->approve($post->load('author'), $request->user(), $request->input('note')), 'help.approved', $post, $request, 'Published.');
    }

    public function reject(Request $request, HelpPost $post): RedirectResponse
    {
        $this->authorize('manage-customers');
        $data = $request->validate(['note' => ['required', 'string', 'min:3', 'max:300']]);

        return $this->attempt(fn () => $this->board->reject($post->load('author'), $request->user(), $data['note']), 'help.rejected', $post, $request, 'Turned down.');
    }

    /** Take a post down, or put it back after looking at the reports. */
    public function hide(Request $request, HelpPost $post): RedirectResponse
    {
        $this->authorize('manage-customers');
        $data = $request->validate(['hidden' => ['required', 'boolean']]);

        $post->update(['hidden' => (bool) $data['hidden']]);
        $this->activity->record($data['hidden'] ? 'help.hidden' : 'help.shown', null, ['post' => $post->id], $request->user());

        return back()->with('status', $data['hidden'] ? __('Taken down.') : __('Shown again.'));
    }

    public function close(Request $request, HelpPost $post): RedirectResponse
    {
        $this->authorize('manage-customers');

        return $this->attempt(fn () => $this->board->close($post, 'admin'), 'help.closed', $post, $request, 'Closed.');
    }

    public function hideComment(Request $request, HelpComment $comment): RedirectResponse
    {
        $this->authorize('manage-customers');
        $data = $request->validate(['hidden' => ['required', 'boolean']]);

        $comment->update(['hidden' => (bool) $data['hidden']]);
        $this->activity->record($data['hidden'] ? 'help.comment_hidden' : 'help.comment_shown', null, ['comment' => $comment->id], $request->user());

        return back()->with('status', $data['hidden'] ? __('Hidden.') : __('Shown again.'));
    }

    private function attempt(callable $do, string $action, HelpPost $post, Request $request, string $done): RedirectResponse
    {
        try {
            $do();
        } catch (StockException $e) {
            throw ValidationException::withMessages(['post' => __($e->getMessage())]);
        }

        $this->activity->record($action, null, ['post' => $post->id], $request->user());

        return back()->with('status', __($done));
    }
}
