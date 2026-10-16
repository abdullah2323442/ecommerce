<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['name', 'logo_url', 'logo_path', 'is_active', 'legacy_id'];

    protected $appends = ['logo'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** Local logo if we have one, otherwise the remote source logo, otherwise null. */
    public function getLogoAttribute(): ?string
    {
        return $this->logo_path ? '/'.ltrim($this->logo_path, '/') : $this->logo_url;
    }

    public function medicines(): HasMany
    {
        return $this->hasMany(Medicine::class);
    }
}
