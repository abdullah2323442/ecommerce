<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicineBarcode extends Model
{
    protected $fillable = ['medicine_id', 'code'];

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    /** Scanners add stray whitespace or line breaks; a code is compared without them. */
    public static function clean(string $code): string
    {
        return preg_replace('/\s+/', '', $code) ?? '';
    }
}
