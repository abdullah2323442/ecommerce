<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notice extends Model
{
    protected $fillable = ['user_id', 'kind', 'importance', 'body', 'read_at'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    /** A notice takes the importance its kind has in config/messages.php unless one is given. */
    protected static function booted(): void
    {
        static::creating(function (Notice $notice) {
            if (! isset($notice->attributes['importance'])) {
                $notice->importance = (string) config("messages.kinds.{$notice->kind}.importance", 'normal');
            }
        });
    }
}
