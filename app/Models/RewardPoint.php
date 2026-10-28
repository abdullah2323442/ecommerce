<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One row of a buyer's reward points: earned on a purchase, reversed when it is cancelled or returned, or turned into a token. */
class RewardPoint extends Model
{
    protected $fillable = ['user_id', 'points', 'kind', 'source_type', 'source_id', 'token_id', 'note'];

    protected function casts(): array
    {
        return ['points' => 'integer'];
    }
}
