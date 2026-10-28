<?php

namespace App\Http\Requests\Admin;

use App\Services\Marketing\BulkSms;
use Illuminate\Foundation\Http\FormRequest;

/** A bulk text campaign: its name, its words and who it is for. Only those who manage customers may write one. */
class SmsCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manage-customers');
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'body' => ['required', 'string', 'max:'.BulkSms::MAX_BODY],
            'types' => ['nullable', 'array', 'max:3'],
            'types.*' => ['string'],
            'tier_ids' => ['nullable', 'array', 'max:20'],
            'tier_ids.*' => ['integer'],
            'area' => ['nullable', 'string', 'max:80'],
            'inactive_days' => ['nullable', 'integer', 'min:7', 'max:730'],
            'owes' => ['nullable', 'boolean'],
        ];
    }
}
