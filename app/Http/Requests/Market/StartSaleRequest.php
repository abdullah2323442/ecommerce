<?php

namespace App\Http\Requests\Market;

use App\Services\Market\Marketplace;
use Illuminate\Foundation\Http\FormRequest;

/** A seller puts one of their products on a flash sale. */
class StartSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && app(Marketplace::class)->canSell($this->user());
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'percent' => ['required', 'integer', 'min:1', 'max:99'],
            'ends_at' => ['required', 'date'],
        ];
    }
}
