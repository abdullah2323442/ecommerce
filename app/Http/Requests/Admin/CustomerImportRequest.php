<?php

namespace App\Http\Requests\Admin;

use App\Services\Import\CustomerImport;
use Illuminate\Foundation\Http\FormRequest;

/** The file of shops the owner wants to bring in. Only those who manage customers may send one. */
class CustomerImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manage-customers');
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['file' => ['required', 'file', 'mimes:csv,txt', 'max:'.(CustomerImport::MAX_BYTES / 1024)]];
    }
}
