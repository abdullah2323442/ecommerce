<?php

namespace App\Services\Gaming;

use App\Models\GameOrder;

/** No automatic top-up yet: the order is left for us to do by hand. */
class ManualTopUp implements TopUpProvider
{
    public function send(GameOrder $order): array
    {
        return ['status' => 'manual'];
    }
}
