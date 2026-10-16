<?php

namespace App\Services\Security;

use App\Models\Invoice;
use App\Models\MarketOrder;
use App\Models\PhoneVerification;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Messages\SmsGateway;
use App\Services\Stock\StockException;
use Illuminate\Support\Facades\DB;

/**
 * Proving a phone number is the person's own (spec ch 03). A six-digit code goes to the phone and must be typed back within ten minutes; five wrong
 * tries and it is void; a new one cannot be asked for within a minute, and only five a day. Only a verified number is kept on the account (one account for
 * each number), and only a verified number may take over the guest orders placed with it, because anyone can type anyone's number into a checkout form.
 */
class PhoneVerifier
{
    public const MINUTES = 10;

    public const TRIES = 5;

    public const PER_DAY = 5;

    public function __construct(private SmsGateway $sms, private ActivityLogger $activity) {}

    /** One way of writing a Bangladesh mobile number: 01XXXXXXXXX. Null when it is not one. */
    public static function normalise(string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        $digits = str_starts_with($digits, '880') ? '0'.substr($digits, 3) : $digits;

        return preg_match('/^01[3-9]\d{8}$/', $digits) ? $digits : null;
    }

    /** A number waiting for its code, if any. */
    public function pending(User $user): ?array
    {
        $p = PhoneVerification::where('user_id', $user->id)->where('expires_at', '>', now())->where('tries', '<', self::TRIES)->latest('id')->first();

        return $p ? ['phone' => $p->phone, 'minutes' => max(1, (int) ceil(now()->diffInSeconds($p->expires_at, false) / 60))] : null;
    }

    /** @throws StockException */
    public function send(User $user, string $raw): void
    {
        $phone = self::normalise($raw) ?? throw new StockException('Write a Bangladesh mobile number, like 01712345678.');

        if (User::where('phone', $phone)->where('id', '!=', $user->id)->exists()) {
            throw new StockException('This number is already used by another account.');
        }

        $last = PhoneVerification::where('user_id', $user->id)->latest('sent_at')->first();

        if ($last && $last->sent_at->gt(now()->subMinute())) {
            throw new StockException('A code was just sent. Please wait a minute before asking for another.');
        }

        if (PhoneVerification::where('user_id', $user->id)->where('sent_at', '>', now()->subDay())->count() >= self::PER_DAY) {
            throw new StockException('Too many codes today. Please try again tomorrow.');
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // An older code for this person is void the moment a new one is asked for.
        PhoneVerification::where('user_id', $user->id)->where('expires_at', '>', now())->update(['expires_at' => now()]);
        PhoneVerification::create(['user_id' => $user->id, 'phone' => $phone, 'code_hash' => $this->hash($code), 'tries' => 0, 'expires_at' => now()->addMinutes(self::MINUTES), 'sent_at' => now()]);

        $this->sms->send($phone, __('Your code is {code}. It works for {n} minutes. Do not tell it to anyone.', ['code' => $code, 'n' => self::MINUTES]));
    }

    /**
     * Check the typed code. On success the number becomes the account's own and the guest orders placed with it are linked.
     *
     * @return array{orders: int} how many guest orders were linked
     *
     * @throws StockException
     */
    public function verify(User $user, string $code): array
    {
        $p = PhoneVerification::where('user_id', $user->id)->where('expires_at', '>', now())->latest('id')->first();

        if (! $p || $p->tries >= self::TRIES) {
            throw new StockException('There is no code waiting. Ask for a new one.');
        }

        $p->increment('tries');

        if (! hash_equals($p->code_hash, $this->hash(trim($code)))) {
            throw new StockException($p->tries >= self::TRIES ? 'Too many wrong tries. Ask for a new code.' : 'That code is not right.');
        }

        return DB::transaction(function () use ($user, $p) {
            // The number may have been taken by someone else while the code was on its way.
            if (User::where('phone', $p->phone)->where('id', '!=', $user->id)->lockForUpdate()->exists()) {
                throw new StockException('This number is already used by another account.');
            }

            $user->forceFill(['phone' => $p->phone, 'phone_verified_at' => now()])->save();
            $p->update(['expires_at' => now()]);
            $n = $this->claimGuestOrders($user);
            $this->activity->record('phone.verified', null, ['orders_linked' => $n], $user);

            return ['orders' => $n];
        });
    }

    /**
     * Orders placed as a guest with this verified number now belong to the account (spec ch 03). Only orders nobody owns are taken, never one that
     * belongs to another account.
     */
    public function claimGuestOrders(User $user): int
    {
        if ($user->phone_verified_at === null || ! $user->phone) {
            return 0;
        }

        $shop = Invoice::whereNull('customer_id')->where('channel', 'online')->whereNotNull('public_token')->where('ship_to->phone', $user->phone)->update(['customer_id' => $user->id]);
        $market = MarketOrder::whereNull('buyer_id')->where('ship_to->phone', $user->phone)->update(['buyer_id' => $user->id]);

        return $shop + $market;
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
