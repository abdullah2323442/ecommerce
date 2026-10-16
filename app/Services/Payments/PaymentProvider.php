<?php

namespace App\Services\Payments;

use App\Models\Payment;

/** One online payment company. */
interface PaymentProvider
{
    public function key(): string;

    /** Is it the company's practice mode (no real money)? */
    public function isLive(): bool;

    /**
     * Open a payment page for this payment. Returns the address to send the buyer to.
     *
     * @param  array{name: string, email: string, phone: string, city: string, product: string, category: string}  $customer
     * @param  array{success: string, fail: string, cancel: string, ipn: string}  $urls
     *
     * @throws PaymentException
     */
    public function start(Payment $payment, array $customer, array $urls): string;

    /**
     * Ask the company about a payment, ourselves. Never trust what the buyer's browser or a message says.
     *
     * @return array{valid: bool, ref: string, amount: float, currency: string, val_id: string, bank_tran_id: ?string, method: ?string, risk_level: ?int}
     *
     * @throws PaymentException
     */
    public function validate(string $valId): array;
}
