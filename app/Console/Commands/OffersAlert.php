<?php

namespace App\Console\Commands;

use App\Services\Sales\OfferProducts;
use Illuminate\Console\Command;

class OffersAlert extends Command
{
    protected $signature = 'offers:alert';

    protected $description = 'Warn the owner once when an offer is nearly out of stock, so new stock can be brought in time';

    public function handle(OfferProducts $offers): int
    {
        $sent = $offers->alertLow();
        $this->info($sent === 0 ? 'No offer needs a warning.' : "Warned about {$sent} offer(s).");

        return self::SUCCESS;
    }
}
