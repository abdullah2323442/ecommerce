<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A share of a job done by one person, with the photo that shows it (spec ch 16.4: wages and progress are the same percentage). */
class CustomProgress extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'custom_progress';

    protected $fillable = ['custom_order_id', 'craftsman_id', 'entered_by', 'percent', 'photo_path', 'note'];

    protected function casts(): array
    {
        return ['percent' => 'decimal:2'];
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
