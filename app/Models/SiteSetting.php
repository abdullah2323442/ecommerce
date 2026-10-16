<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One group of site words and look (spec ch 28: nothing hard-coded). */
class SiteSetting extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'group';

    protected $keyType = 'string';

    protected $fillable = ['group', 'value', 'updated_by'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }
}
