<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Money paid to a craftsman: wages, or the advance given to start. */
class CraftsmanPayment extends Model
{
    protected $fillable = ['craftsman_id', 'custom_order_id', 'kind', 'amount', 'note', 'paid_on', 'paid_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_on' => 'date'];
    }
}
