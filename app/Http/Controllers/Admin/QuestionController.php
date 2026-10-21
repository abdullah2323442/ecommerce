<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ListingQuestion;
use App\Models\ProductQuestion;
use App\Services\Audit\ActivityLogger;
use App\Services\Market\ListingQuestions;
use App\Services\Messages\Messenger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Questions buyers asked under products: answer them, or hide the ones that should not be shown (spec ch 23). */
class QuestionController extends Controller
{
    public function __construct(private Messenger $messages, private ActivityLogger $activity) {}

    public function index(): Response
    {
        $this->authorize('handle-orders');

        $row = fn (ProductQuestion $q) => [
            'id' => $q->id, 'medicine' => ['id' => $q->medicine_id, 'name' => trim($q->medicine->brand_name.' '.$q->medicine->strength)],
            'asker' => $q->asker?->name, 'question' => $q->question, 'answer' => $q->answer, 'at' => $q->created_at->toIso8601String(),
            'answered_at' => $q->answered_at?->toIso8601String(), 'hidden' => $q->hidden,
        ];

        $seller = fn (ListingQuestion $q) => [
            'id' => $q->id, 'listing' => ['id' => $q->listing_id, 'name' => $q->listing->title], 'seller' => $q->seller->business_name ?: $q->seller->name, 'asker' => $q->asker?->name,
            'question' => $q->question, 'answer' => $q->answer, 'at' => $q->created_at->toIso8601String(), 'answered_at' => $q->answered_at?->toIso8601String(), 'hidden' => $q->hidden,
        ];

        return Inertia::render('admin/questions', [
            // A question a seller left too long comes to us (spec ch 23).
            'fromSellers' => ListingQuestion::waiting()->whereNotNull('escalated_at')->with('listing:id,title', 'seller:id,name,business_name', 'asker:id,name')->oldest('id')->get()->map($seller),
            'waiting' => ProductQuestion::waiting()->with('medicine:id,brand_name,strength', 'asker:id,name')->oldest('id')->get()->map($row),
            'answered' => ProductQuestion::whereNotNull('answered_at')->with('medicine:id,brand_name,strength', 'asker:id,name')->latest('answered_at')->limit(30)->get()->map($row),
        ]);
    }

    public function answer(Request $request, ProductQuestion $question): RedirectResponse
    {
        $this->authorize('handle-orders');
        $data = $request->validate(['answer' => ['required', 'string', 'min:2', 'max:1500']]);

        $first = $question->answered_at === null;
        $question->update(['answer' => trim($data['answer']), 'answered_by' => $request->user()->id, 'answered_at' => $question->answered_at ?? now(), 'hidden' => false]);
        $this->activity->record($first ? 'question.answered' : 'question.answer_edited', $question->medicine, ['question' => $question->id], $request->user());

        // The asker is told once, when it is first answered.
        if ($first && $question->asker) {
            $this->messages->send($question->asker, 'question_answered', ['name' => $question->medicine->brand_name], '/medicines/'.$question->medicine_id);
        }

        return back()->with('status', __('Answer saved.'));
    }

    public function answerSeller(Request $request, ListingQuestion $question, ListingQuestions $questions): RedirectResponse
    {
        $this->authorize('handle-orders');
        $data = $request->validate(['answer' => ['required', 'string', 'min:2', 'max:1500']]);

        $questions->answer($question->load('listing', 'asker'), $request->user(), $data['answer'], true);
        $this->activity->record('question.seller_answered', $question->listing->seller, ['question' => $question->id], $request->user());

        return back()->with('status', __('Answer saved.'));
    }

    public function hideSeller(Request $request, ListingQuestion $question, ListingQuestions $questions): RedirectResponse
    {
        $this->authorize('handle-orders');
        $data = $request->validate(['hidden' => ['required', 'boolean']]);
        $questions->hide($question, (bool) $data['hidden']);

        return back()->with('status', $data['hidden'] ? __('Hidden.') : __('Shown again.'));
    }

    public function hide(Request $request, ProductQuestion $question): RedirectResponse
    {
        $this->authorize('handle-orders');
        $data = $request->validate(['hidden' => ['required', 'boolean']]);

        $question->update(['hidden' => (bool) $data['hidden']]);
        $this->activity->record($data['hidden'] ? 'question.hidden' : 'question.shown', $question->medicine, ['question' => $question->id], $request->user());

        return back()->with('status', $data['hidden'] ? __('Hidden.') : __('Shown again.'));
    }
}
