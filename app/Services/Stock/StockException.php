<?php

namespace App\Services\Stock;

use RuntimeException;

/** A stock rule was broken. The message is safe to show to the admin. */
class StockException extends RuntimeException
{
    /** What kind of rule was broken, for code that must react to it. Never read the wording for this: the words change with the language. */
    public ?string $reason = null;

    public function because(string $reason): static
    {
        $this->reason = $reason;

        return $this;
    }

    public static function duplicateInvoice(string $number): self
    {
        return (new self(__('Invoice {number} from this source was already entered.', ['number' => $number])))->because('duplicate_invoice');
    }

    public static function noPercent(string $brand): self
    {
        return new self(__('No purchase % for {brand}. Set one on the source, the company or the medicine.', ['brand' => $brand]));
    }

    public static function insufficient(string $brand): self
    {
        return (new self(__('Not enough stock of {brand}.', ['brand' => $brand])))->because('insufficient');
    }

    public static function loss(string $brand): self
    {
        return (new self(__('Selling {brand} at that price would be a loss. Only the admin can approve it.', ['brand' => $brand])))->because('loss');
    }

    public static function adminOnly(): self
    {
        return new self(__('Only the admin can do this.'));
    }
}
