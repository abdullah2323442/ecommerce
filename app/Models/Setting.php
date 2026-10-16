<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Small global switches, one row each. The value is wrapped as {"v": ...} so booleans and numbers survive. */
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    public static function read(string $key, mixed $default = null): mixed
    {
        $row = static::find($key);

        return $row ? ($row->value['v'] ?? $default) : $default;
    }

    public static function write(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => ['v' => $value]]);
    }
}
