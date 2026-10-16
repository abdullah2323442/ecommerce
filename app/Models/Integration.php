<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One connected outside service. The secret fields are encrypted at rest with the app key and are never put on a page. */
class Integration extends Model
{
    protected $fillable = ['key', 'type', 'enabled', 'mode', 'credentials', 'settings', 'tested_at', 'test_ok', 'test_note', 'updated_by'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'credentials' => 'encrypted:array', 'settings' => 'array', 'tested_at' => 'datetime', 'test_ok' => 'boolean'];
    }
}
