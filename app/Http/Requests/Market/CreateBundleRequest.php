<?php

namespace App\Http\Requests\Market;

use App\Services\Market\Marketplace;
use Illuminate\Foundation\Http\FormRequest;

/** A seller joins a few of their products into a bundle. */
class CreateBundleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && app(Marketplace::class)->canSell($this->user());
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:80'],
            'percent' => ['required', 'integer', 'min:1', 'max:99'],
            'listing_ids' => ['required', 'array', 'max:10'],
            'listing_ids.*' => ['integer'],
        ];
    }
}
