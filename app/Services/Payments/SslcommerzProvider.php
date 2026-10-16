<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Services\Integrations\Integrations;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * SSLCommerz: cards, internet banking, bKash, Nagad, Rocket and more behind one account. Follows their published v4 steps:
 *  1. POST gwprocess/v4/api.php from the server with the store login and the order, and they answer with a GatewayPageURL to send the buyer to;
 *  2. the buyer pays there, and is sent back to our success/fail/cancel address (which only starts a check) while they also post to our IPN address;
 *  3. GET validator/api/validationserverAPI.php with their val_id and the store login: only this answer, with our own transaction id, amount and currency
 *     matching, is allowed to mark anything paid.
 * Practice mode (Testing in Connections) talks to their sandbox; Live talks to the real gateway.
 */
class SslcommerzProvider implements PaymentProvider
{
    private const SANDBOX = 'https://sandbox.sslcommerz.com';

    private const LIVE = 'https://securepay.sslcommerz.com';

    public function __construct(private Integrations $integrations) {}

    public function key(): string
    {
        return 'sslcommerz';
    }

    public function isLive(): bool
    {
        return ($this->integrations->active('payment:sslcommerz')['mode'] ?? 'test') === 'live';
    }

    public function start(Payment $payment, array $customer, array $urls): string
    {
        [$id, $pass] = $this->login();
        $amount = (float) $payment->amount;

        // Their limits: 10.00 to 500000.00 taka.
        if ($amount < 10 || $amount > 500000) {
            throw new PaymentException('Online payment works for amounts from 10 to 500000 taka.');
        }

        try {
            $res = Http::asForm()->timeout(20)->withoutRedirecting()->post($this->base().'/gwprocess/v4/api.php', [
                'store_id' => $id, 'store_passwd' => $pass, 'total_amount' => number_format($amount, 2, '.', ''), 'currency' => $payment->currency, 'tran_id' => $payment->ref,
                'success_url' => $urls['success'], 'fail_url' => $urls['fail'], 'cancel_url' => $urls['cancel'], 'ipn_url' => $urls['ipn'],
                'product_name' => mb_substr($customer['product'], 0, 100), 'product_category' => $customer['category'], 'product_profile' => 'general', 'shipping_method' => 'NO', 'num_of_item' => 1, 'emi_option' => 0,
                'cus_name' => mb_substr($customer['name'], 0, 50), 'cus_email' => $customer['email'], 'cus_add1' => mb_substr($customer['city'], 0, 50), 'cus_city' => mb_substr($customer['city'], 0, 50),
                'cus_postcode' => '1000', 'cus_country' => 'Bangladesh', 'cus_phone' => $customer['phone'],
            ]);
        } catch (ConnectionException) {
            throw new PaymentException('Could not reach the payment company. Please try again.');
        }

        $json = (array) $res->json();
        $url = (string) ($json['GatewayPageURL'] ?? '');

        // Only a page on their own site is accepted: a changed answer must not send a buyer somewhere else.
        if (! $res->successful() || ($json['status'] ?? '') !== 'SUCCESS' || ! $this->theirs($url)) {
            throw new PaymentException('The payment company did not open a payment page. Please try again, or pay on delivery.');
        }

        return $url;
    }

    public function validate(string $valId): array
    {
        [$id, $pass] = $this->login();

        try {
            $res = Http::timeout(20)->withoutRedirecting()->get($this->base().'/validator/api/validationserverAPI.php', ['val_id' => $valId, 'store_id' => $id, 'store_passwd' => $pass, 'format' => 'json', 'v' => 1]);
        } catch (ConnectionException) {
            throw new PaymentException('Could not reach the payment company to check the payment.');
        }

        if (! $res->successful()) {
            throw new PaymentException('The payment company did not answer the check.');
        }

        $j = (array) $res->json();

        return [
            'valid' => in_array($j['status'] ?? '', ['VALID', 'VALIDATED'], true), 'ref' => (string) ($j['tran_id'] ?? ''), 'amount' => (float) ($j['amount'] ?? 0), 'currency' => (string) ($j['currency'] ?? ''),
            'val_id' => (string) ($j['val_id'] ?? $valId), 'bank_tran_id' => isset($j['bank_tran_id']) ? (string) $j['bank_tran_id'] : null,
            'method' => isset($j['card_type']) ? mb_substr((string) $j['card_type'], 0, 60) : null, 'risk_level' => isset($j['risk_level']) ? (int) $j['risk_level'] : null,
        ];
    }

    // ------------------------------------------------------------------ helpers

    private function base(): string
    {
        return $this->isLive() ? self::LIVE : self::SANDBOX;
    }

    /** @return array{0: string, 1: string} */
    private function login(): array
    {
        $on = $this->integrations->active('payment:sslcommerz') ?? throw new PaymentException('Online payment is not switched on.');
        $id = (string) ($on['settings']['store_id'] ?? '');
        $pass = (string) ($on['credentials']['store_password'] ?? '');

        if ($id === '' || $pass === '') {
            throw new PaymentException('The payment keys are not saved yet.');
        }

        return [$id, $pass];
    }

    private function theirs(string $url): bool
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        return str_starts_with($url, 'https://') && ($host === 'sslcommerz.com' || str_ends_with($host, '.sslcommerz.com'));
    }
}
