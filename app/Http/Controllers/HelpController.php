<?php

namespace App\Http\Controllers;

use App\Models\HelpPost;
use App\Services\Help\HelpBoard;
use App\Services\Stock\StockException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Help board (spec ch 19). Anyone can read the approved posts; signed-in people post, comment and report. A post is only a way
 * to connect: the deal is between the two people, and the page says so.
 */
class HelpController extends Controller
{
    public function __construct(private HelpBoard $board) {}

    public function index(Request $request): Response
    {
        $category = in_array($request->query('category'), HelpPost::CATEGORIES, true) ? (string) $request->query('category') : null;
        $q = trim((string) $request->query('q', ''));

        $posts = HelpPost::shown()->withCount(['comments as comments_count' => fn ($c) => $c->where('hidden', false)])
            ->when($category, fn ($query) => $query->where('category', $category))
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('title', 'like', '%'.addcslashes($q, '%_\\').'%')->orWhere('body', 'like', '%'.addcslashes($q, '%_\\').'%')))
            ->latest('approved_at')->paginate(15)->withQueryString()
            ->through(fn (HelpPost $p) => $this->summary($p));

        return Inertia::render('help/index', ['posts' => $posts, 'category' => $category, 'q' => $q, 'categories' => HelpPost::CATEGORIES]);
    }

    public function show(Request $request, HelpPost $post): Response
    {
        $mine = $request->user()?->id === $post->user_id;
        abort_unless($post->isShown() || $mine, 404);

        $viewer = $request->user();

        return Inertia::render('help/show', [
            'post' => $this->summary($post) + [
                'body' => $post->body,
                // How to reach the person is for signed-in accounts only, so a list of numbers cannot be copied off by anyone.
                'contact' => $viewer ? $post->contact : null,
                'status' => $post->status, 'review_note' => $mine ? $post->review_note : null,
                'mine' => $mine, 'asked' => $mine && $post->status === 'open' && $post->asked_at !== null,
                'reported' => $viewer ? $post->reports()->where('user_id', $viewer->id)->exists() : false,
            ],
            'comments' => $post->comments()->where('hidden', false)->oldest('id')->get()->map(fn ($c) => ['id' => $c->id, 'at' => $c->created_at->toIso8601String()] + $this->board->render($c->body, $viewer)),
            'canComment' => $viewer !== null && $post->isShown(),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('help/create', ['categories' => HelpPost::CATEGORIES, 'refusal' => $this->refusal($request)]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'category' => ['required', Rule::in(HelpPost::CATEGORIES)],
            'title' => ['required', 'string', 'min:5', 'max:120'],
            'body' => ['required', 'string', 'min:20', 'max:2000'],
            'contact' => ['required', 'string', 'min:5', 'max:120'],
        ]);

        try {
            $post = $this->board->create($request->user(), $data);
        } catch (StockException $e) {
            return back()->withErrors(['post' => __($e->getMessage(), ['n' => $this->board->activeLimit()])]);
        }

        return to_route('help.mine')->with('status', __('Sent. We read every post before it is shown. You will hear from us.'));
    }

    public function mine(Request $request): Response
    {
        return Inertia::render('help/mine', [
            'posts' => HelpPost::where('user_id', $request->user()->id)->latest('id')->limit(50)->get()->map(fn (HelpPost $p) => $this->summary($p) + [
                'status' => $p->status, 'review_note' => $p->review_note, 'asked' => $p->status === 'open' && $p->asked_at !== null, 'hidden' => $p->hidden,
            ]),
            'refusal' => $this->refusal($request),
        ]);
    }

    public function close(Request $request, HelpPost $post): RedirectResponse
    {
        abort_unless($post->user_id === $request->user()->id, 404);

        return $this->attempt(fn () => $this->board->close($post, 'owner'), 'Closed.');
    }

    public function confirm(Request $request, HelpPost $post): RedirectResponse
    {
        abort_unless($post->user_id === $request->user()->id, 404);

        return $this->attempt(fn () => $this->board->confirm($post), 'Thank you. It stays for another month.');
    }

    public function comment(Request $request, HelpPost $post): RedirectResponse
    {
        abort_unless($post->isShown(), 404);
        $data = $request->validate(['comment' => ['required', 'string', 'max:600']]);

        return $this->attempt(fn () => $this->board->comment($post, $request->user(), $data['comment'], $request->getHost()), 'Added.', 'comment');
    }

    public function report(Request $request, HelpPost $post): RedirectResponse
    {
        abort_unless($post->isShown(), 404);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:200']]);

        return $this->attempt(fn () => $this->board->report($post, $request->user(), $data['reason'] ?? null), 'Thank you. We will look at it.');
    }

    private function attempt(callable $do, string $done, string $key = 'post'): RedirectResponse
    {
        try {
            $do();
        } catch (StockException $e) {
            return back()->withErrors([$key => __($e->getMessage(), ['n' => $this->board->activeLimit()])]);
        }

        return back()->with('status', __($done));
    }

    private function refusal(Request $request): ?string
    {
        $why = $this->board->refusal($request->user());

        return $why ? __($why, ['n' => $this->board->activeLimit()]) : null;
    }

    private function summary(HelpPost $p): array
    {
        return [
            'id' => $p->id, 'category' => $p->category, 'title' => $p->title, 'excerpt' => mb_strimwidth($p->body, 0, 180, '…'),
            'at' => ($p->approved_at ?? $p->created_at)->toIso8601String(), 'comments' => $p->comments_count ?? null,
        ];
    }
}
