<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A promotional text to many people: the words, who it was for, and how it went. */
class SmsCampaign extends Model
{
    protected $fillable = ['name', 'body', 'audience', 'audience_label', 'status', 'recipients', 'created_by', 'started_at', 'finished_at'];

    protected function casts(): array
    {
        return ['audience' => 'array', 'recipients' => 'integer', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function people(): HasMany
    {
        return $this->hasMany(SmsRecipient::class, 'campaign_id');
    }
}
