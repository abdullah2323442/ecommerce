<?php

namespace App\Http\Requests\Admin;

use App\Services\Ledger\Payables;
use Illuminate\Foundation\Http\FormRequest;

/** A payment to a company or supplier on the credit ledger. Purchase costs are the owner's alone, so only those who manage stock may record one. */
class CompanyPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manage-stock');
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0', 'max:1000000000'],
            'method' => ['required', 'in:'.implode(',', Payables::METHODS)],
            'note' => ['nullable', 'string', 'max:200'],
        ];
    }
}
