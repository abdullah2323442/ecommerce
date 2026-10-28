<?php

namespace App\Http\Requests\Admin;

use App\Services\Ledger\Receivables;
use Illuminate\Foundation\Http\FormRequest;

/** A payment from a customer on the credit ledger. Only those who may see the reports may take one. */
class ReceivePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('view-reports');
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0', 'max:100000000'],
            'method' => ['required', 'in:'.implode(',', Receivables::METHODS)],
            'note' => ['nullable', 'string', 'max:200'],
        ];
    }
}
