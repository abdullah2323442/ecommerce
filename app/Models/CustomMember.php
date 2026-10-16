<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Someone on a made-to-order job: the one mainly responsible, or a helper (spec ch 16.4). */
class CustomMember extends Model
{
    protected $fillable = ['custom_order_id', 'craftsman_id', 'role', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(CustomOrder::class, 'custom_order_id');
    }

    public function craftsman(): BelongsTo
    {
        return $this->belongsTo(User::class, 'craftsman_id');
    }
}
