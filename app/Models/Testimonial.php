<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One buyer's words about the shop, for the home page. */
class Testimonial extends Model
{
    protected $fillable = ['name', 'place', 'quote', 'quote_bn', 'rating', 'is_active', 'sort'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'rating' => 'integer', 'sort' => 'integer'];
    }
}
