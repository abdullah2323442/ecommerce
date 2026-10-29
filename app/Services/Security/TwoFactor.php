<?php

namespace App\Services\Security;

use App\Enums\AccountType;
use App\Models\Setting;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Stock\StockException;
use Illuminate\Support\Facades\Hash;

/**
 * A second step at sign-in with an authenticator app (Google Authenticator, Microsoft Authenticator, Authy, 1Password…). Time-based one-time codes as in
 * RFC 6238 (HMAC-SHA1, 6 digits, 30 seconds), written here so no outside service or package is needed. Care taken:
 *  - the secret is encrypted in the database and shown only while setting up;
 *  - setting up only counts once a code from the phone has been proven;
 *  - a code is accepted for one 30-second step either side (clock drift), and never twice (the last step used is kept);
 *  - eight recovery codes are shown once, kept as hashes, and each works only once;
 *  - turning it off, or making new recovery codes, needs the password.
 */
class TwoFactor
{
    private const STEP = 30;

    private const DIGITS = 6;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function __construct(private ActivityLogger $activity) {}

    /**
     * Whether this account must use two-step sign-in (spec ch 03 and 26: the owner, staff, wholesale and paikari accounts).
     * The owner and staff are asked for it by default on the live site and the owner can switch it off; the two kinds of shop are
     * asked only when the owner turns it on, because a shop owner needs a phone app for the code.
     */
    public function requiredFor(User $user): bool
    {
        return match ($user->account_type) {
            AccountType::Staff, AccountType::Admin => (bool) Setting::read('security.require_2fa_staff', app()->isProduction()),
            AccountType::Paikari, AccountType::Wholesaler => (bool) Setting::read('security.require_2fa_trade', false),
            default => false,
        };
    }

    public function enabled(User $user): bool
    {
        return $user->two_factor_secret !== null && $user->two_factor_confirmed_at !== null;
    }

    /** Start setting up: a new secret that does not count until a code is proven. @return array{secret: string, uri: string} */
    public function begin(User $user, string $password): array
    {
        $this->mustKnowPassword($user, $password);

        if ($this->enabled($user)) {
            throw new StockException('Two-step sign-in is already on.');
        }

        $secret = $this->base32(random_bytes(20));
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => null, 'two_factor_last_step' => null, 'two_factor_recovery_codes' => null])->save();

        return $this->setup($user);
    }

    /** What the setup screen shows while a secret waits for its first code. @return array{secret: string, uri: string}|null */
    public function pending(User $user): ?array
    {
        return $user->two_factor_secret !== null && $user->two_factor_confirmed_at === null ? $this->setup($user) : null;
    }

    /**
     * The first code from the phone turns it on. Returns the recovery codes, to be shown once.
     *
     * @return array<int, string>
     *
     * @throws StockException
     */
    public function confirm(User $user, string $code): array
    {
        if ($user->two_factor_secret === null || $user->two_factor_confirmed_at !== null) {
            throw new StockException('Start the setup first.');
        }

        if (! $this->accept($user, $code)) {
            throw new StockException('That code is not right. Check the time on your phone and try the newest code.');
        }

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
        $this->activity->record('security.two_factor_on', $user, [], $user);

        return $this->newRecoveryCodes($user);
    }

    /** Check a code at sign-in or at setup: right, in the window, and not used before. */
    public function accept(User $user, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (! preg_match('/^\d{6}$/', $code) || $user->two_factor_secret === null) {
            return false;
        }

        $now = intdiv(time(), self::STEP);
        $last = (int) $user->two_factor_last_step;

        for ($step = $now - 1; $step <= $now + 1; $step++) {
            if ($step > $last && hash_equals($this->code($user->two_factor_secret, $step), $code)) {
                $user->forceFill(['two_factor_last_step' => $step])->save();

                return true;
            }
        }

        return false;
    }

    /** A recovery code works once and is then gone. */
    public function useRecoveryCode(User $user, string $code): bool
    {
        $code = strtolower(trim($code));
        $hashes = (array) json_decode((string) $user->two_factor_recovery_codes, true);

        foreach ($hashes as $i => $hash) {
            if (hash_equals($hash, $this->hashCode($code))) {
                unset($hashes[$i]);
                $user->forceFill(['two_factor_recovery_codes' => json_encode(array_values($hashes))])->save();
                $this->activity->record('security.recovery_code_used', $user, ['left' => count($hashes)], $user);

                return true;
            }
        }

        return false;
    }

    public function recoveryCodesLeft(User $user): int
    {
        return count((array) json_decode((string) $user->two_factor_recovery_codes, true));
    }

    /** @return array<int, string> @throws StockException */
    public function regenerate(User $user, string $password): array
    {
        $this->mustKnowPassword($user, $password);

        if (! $this->enabled($user)) {
            throw new StockException('Two-step sign-in is not on.');
        }

        $this->activity->record('security.recovery_codes_renewed', $user, [], $user);

        return $this->newRecoveryCodes($user);
    }

    /** @throws StockException */
    public function disable(User $user, string $password): void
    {
        $this->mustKnowPassword($user, $password);
        $this->clear($user);
        $this->activity->record('security.two_factor_off', $user, [], $user);
    }

    /** The owner switches it off for someone who lost their phone. */
    public function reset(User $user, User $by): void
    {
        $this->clear($user);
        $this->activity->record('security.two_factor_reset', $user, [], $by);
    }

    // ------------------------------------------------------------------ the maths

    /** The six digits for one 30-second step (RFC 4226 with the step as the counter). */
    public function code(string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), $this->unbase32($secret), true);
        $offset = ord($hash[19]) & 0xF;
        $bin = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($bin % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    public function currentCode(string $secret): string
    {
        return $this->code($secret, intdiv(time(), self::STEP));
    }

    // ------------------------------------------------------------------ helpers

    /** @return array{secret: string, uri: string} */
    private function setup(User $user): array
    {
        $issuer = (string) config('brand.name', config('app.name'));

        return [
            'secret' => $user->two_factor_secret,
            'uri' => 'otpauth://totp/'.rawurlencode($issuer.':'.$user->email).'?secret='.$user->two_factor_secret.'&issuer='.rawurlencode($issuer).'&algorithm=SHA1&digits=6&period=30',
        ];
    }

    /** @return array<int, string> */
    private function newRecoveryCodes(User $user): array
    {
        $codes = collect(range(1, 8))->map(fn () => strtolower(bin2hex(random_bytes(3)).'-'.bin2hex(random_bytes(3))))->all();
        $user->forceFill(['two_factor_recovery_codes' => json_encode(array_map(fn ($c) => $this->hashCode($c), $codes))])->save();

        return $codes;
    }

    private function hashCode(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    private function clear(User $user): void
    {
        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null, 'two_factor_last_step' => null])->save();
    }

    /** @throws StockException */
    private function mustKnowPassword(User $user, string $password): void
    {
        if (! Hash::check($password, $user->password)) {
            throw new StockException('That password is not right.');
        }
    }

    private function base32(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }

        return collect(str_split($bits, 5))->map(fn ($b) => self::ALPHABET[bindec(str_pad($b, 5, '0'))])->implode('');
    }

    private function unbase32(string $text): string
    {
        $bits = '';
        foreach (str_split(strtoupper($text)) as $c) {
            $bits .= str_pad(decbin(strpos(self::ALPHABET, $c) ?: 0), 5, '0', STR_PAD_LEFT);
        }

        return implode('', array_map(fn ($b) => chr(bindec($b)), array_filter(str_split($bits, 8), fn ($b) => strlen($b) === 8)));
    }
}
