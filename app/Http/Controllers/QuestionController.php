<?php

namespace App\Http\Controllers;

use App\Models\Medicine;
use App\Models\Notice;
use App\Models\ProductQuestion;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** A signed-in buyer asks a question under a product (spec ch 23, Q&A). We are told; everyone reads the answer. */
class QuestionController extends Controller
{
    /** Few a day: this is a question, not a chat. */
    private const PER_DAY = 10;

    public function store(Request $request, Medicine $medicine): RedirectResponse
    {
        abort_unless($medicine->is_active, 404);

        $data = $request->validate(['question' => ['required', 'string', 'min:8', 'max:500']], [
            'question.min' => __('Write a little more, so we can answer.'),
        ]);
        $text = trim(preg_replace('/\s+/u', ' ', $data['question']) ?? '');

        // The talk stays on this site: no addresses, no numbers to call.
        if (preg_match('~(https?://|www\.|@[a-z0-9_]+\.|\d[\d\s-]{8,}\d)~iu', $text)) {
            throw ValidationException::withMessages(['question' => __('Please do not write web addresses or phone numbers in a question.')]);
        }

        $user = $request->user();
        if (ProductQuestion::where('user_id', $user->id)->where('created_at', '>=', now()->subDay())->count() >= self::PER_DAY) {
            throw ValidationException::withMessages(['question' => __('That is enough questions for today. Please try again tomorrow.')]);
        }

        $question = ProductQuestion::create(['medicine_id' => $medicine->id, 'user_id' => $user->id, 'question' => $text]);

        foreach (User::withPermission('orders') as $staff) {
            Notice::create(['user_id' => $staff->id, 'kind' => 'question_new', 'body' => mb_substr($medicine->brand_name.': '.$question->question, 0, 290)]);
        }

        return back()->with('status', __('Your question is sent. We will answer here.'));
    }
}
