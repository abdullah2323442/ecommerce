<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** What was typed on the purchase screen so far. One per person. It never touches stock. */
class StockEntryDraft extends Model
{
    protected $fillable = ['user_id', 'payload'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
