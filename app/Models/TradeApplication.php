<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradeApplication extends Model
{
    protected $fillable = [
        'user_id', 'business_name', 'business_kind', 'categories', 'phone', 'address', 'license_number', 'license_path', 'license_name',
        'second_path', 'second_name', 'note', 'status', 'reviewed_by', 'reviewed_at', 'review_note',
    ];

    protected function casts(): array
    {
        return ['categories' => 'array', 'reviewed_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
