<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** What a buyer thought of the delivery and the service on one order (spec ch 20). Buyers never see the names; we do. */
class DeliveryRating extends Model
{
    protected $fillable = ['invoice_id', 'delivery_person_id', 'staff_id', 'delivery_stars', 'service_stars', 'comment'];

    protected function casts(): array
    {
        return ['delivery_stars' => 'integer', 'service_stars' => 'integer'];
    }
}
