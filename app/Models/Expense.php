<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    public const CATEGORIES = ['rent', 'salaries', 'electricity', 'internet', 'transport', 'packaging', 'other'];

    protected $fillable = ['spent_on', 'category', 'amount', 'note', 'recurring', 'recurring_until', 'created_by'];

    protected function casts(): array
    {
        return ['spent_on' => 'date', 'amount' => 'decimal:2', 'recurring' => 'boolean', 'recurring_until' => 'date'];
    }
}
