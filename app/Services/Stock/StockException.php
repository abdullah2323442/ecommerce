<?php

namespace App\Services\Stock;

use RuntimeException;

/** A stock rule was broken. The message is safe to show to the admin. */
class StockException extends RuntimeException
{
    public static function duplicateInvoice(string $number): self
    {
        return new self("Invoice {$number} from this source was already entered.");
    }

    public static function noPercent(string $brand): self
    {
        return new self("No purchase % for {$brand}. Set one on the source, the company or the medicine.");
    }

    public static function insufficient(string $brand): self
    {
        return new self("Not enough stock of {$brand}.");
    }

    public static function loss(string $brand): self
    {
        return new self("Selling {$brand} at that price would be a loss. Only the admin can approve it.");
    }

    public static function adminOnly(): self
    {
        return new self('Only the admin can do this.');
    }
}
