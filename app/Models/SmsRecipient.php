<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One person in a text campaign, and whether their text went. */
class SmsRecipient extends Model
{
    public $timestamps = false;

    protected $fillable = ['campaign_id', 'user_id', 'phone', 'status', 'note', 'sent_at'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }
}
