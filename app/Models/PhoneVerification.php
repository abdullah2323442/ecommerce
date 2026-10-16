<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A code sent to a phone, waiting to be typed back (spec ch 03: one account per phone number, checked by a code). */
class PhoneVerification extends Model
{
    protected $fillable = ['user_id', 'phone', 'code_hash', 'tries', 'expires_at', 'sent_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'sent_at' => 'datetime'];
    }
}
