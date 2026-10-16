<?php

namespace App\Services\Wallet;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Referral credit (spec ch 24): "Return money, tokens, referral credit all collect in one place" and referral works in any sector. A person shares their code;
 * a friend who opens an account by it and has a first order completed earns the sharer one Aqua Token, once. Nobody earns from their own account, and the
 * reward is the owner's setting (0 means the programme is off).
 */
class Referrals
{
    public function __construct(private Tokens $tokens) {}

    public function reward(): float
    {
        return max(0.0, (float) Setting::read('referral.reward', 0));
    }

    public function codeFor(User $user): string
    {
        if ($user->referral_code === null) {
            do {
                $code = strtoupper(Str::random(8));
            } while (User::where('referral_code', $code)->exists());

            $user->forceFill(['referral_code' => $code])->save();
        }

        return $user->referral_code;
    }

    /** A new account joined by someone's code: remember who sent them (never themselves, never an unknown code). */
    public function attach(User $new, ?string $code): void
    {
        $code = $code !== null ? strtoupper(trim($code)) : '';

        if ($code === '' || ($from = User::where('referral_code', $code)->first()) === null || $from->id === $new->id) {
            return;
        }

        $new->forceFill(['referred_by' => $from->id])->save();
    }

    /** The referred person's first order is complete: the sharer gets the token. Only once per friend. */
    public function qualify(?User $buyer): void
    {
        if ($buyer === null || $buyer->referred_by === null || $buyer->referral_rewarded_at !== null || $this->reward() < 1) {
            return;
        }

        $referrer = User::find($buyer->referred_by);

        if ($referrer === null || $referrer->id === $buyer->id) {
            return;
        }

        $this->tokens->give($referrer, $this->reward(), 'Referral: a friend you invited ordered', null, $referrer);
        $buyer->forceFill(['referral_rewarded_at' => now()])->save();
    }

    /** @return array{code: string, link: string, joined: int, rewarded: int, reward: float} */
    public function summary(User $user): array
    {
        $code = $this->codeFor($user);

        return [
            'code' => $code, 'link' => url('/r/'.$code), 'reward' => $this->reward(),
            'joined' => User::where('referred_by', $user->id)->count(), 'rewarded' => User::where('referred_by', $user->id)->whereNotNull('referral_rewarded_at')->count(),
        ];
    }
}
