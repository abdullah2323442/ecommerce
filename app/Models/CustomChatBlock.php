<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A phone number or link someone tried to put in the chat. */
class CustomChatBlock extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['custom_order_id', 'user_id', 'role', 'reason', 'excerpt'];
}
