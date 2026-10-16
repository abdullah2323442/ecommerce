<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HelpReport extends Model
{
    protected $fillable = ['help_post_id', 'user_id', 'reason'];
}
