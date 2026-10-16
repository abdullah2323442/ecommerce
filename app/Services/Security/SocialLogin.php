<?php

namespace App\Services\Security;

use App\Enums\AccountType;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Integrations\Integrations;
use App\Services\Stock\StockException;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialUser;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Sign in with Google or Facebook, switched on from Admin > Connections. The keys come from there at the moment they are needed. Rules that keep it safe:
 *  - an outside account already linked to a person signs that person in;
 *  - an unknown outside account is joined to an existing person by email ONLY when Google says the address is verified (Facebook never): otherwise anyone could
 *    open an account at the provider with someone else's address and walk into theirs;
 *  - only a plain buyer account can be signed into this way; staff, the owner, shops, trade and the rest keep their password (and their second step);
 *  - a new person is always a plain buyer account, never anything with powers;
 *  - the outside account only ever proves who someone is; two-step sign-in, if on, is still asked afterwards (done by the caller).
 */
class SocialLogin
{
    public const PROVIDERS = ['google' => 'Google', 'facebook' => 'Facebook'];

    public function __construct(private Integrations $integrations, private ActivityLogger $activity) {}

    /** The ones the owner has switched on, for the sign-in buttons. @return array<int, array{key: string, name: string}> */
    public function enabled(): array
    {
        return collect(self::PROVIDERS)->filter(fn ($name, $key) => $this->integrations->active("social:{$key}") !== null)
            ->map(fn ($name, $key) => ['key' => $key, 'name' => $name])->values()->all();
    }

    public function isOn(string $provider): bool
    {
        return isset(self::PROVIDERS[$provider]) && $this->integrations->active("social:{$provider}") !== null;
    }

    public function redirect(string $provider): RedirectResponse
    {
        $this->configure($provider);

        return Socialite::driver($provider)->scopes($provider === 'google' ? ['openid', 'email', 'profile'] : ['email', 'public_profile'])->redirect();
    }

    /**
     * The person coming back from the provider: who they are here.
     *
     * @return array{user: User, created: bool}
     *
     * @throws StockException
     */
    public function resolve(string $provider): array
    {
        $this->configure($provider);
        $outside = Socialite::driver($provider)->user();

        if (! $outside->getId()) {
            throw new StockException('The sign-in did not work. Please try again.');
        }

        // Already joined to a person.
        if ($linked = SocialAccount::where('provider', $provider)->where('provider_id', (string) $outside->getId())->with('user')->first()) {
            $this->mustBeAllowed($linked->user);

            return ['user' => $linked->user, 'created' => false];
        }

        $email = $outside->getEmail() ? Str::lower(trim($outside->getEmail())) : null;

        if (! $email) {
            throw new StockException('That account did not share an email address, so we cannot sign you in with it. Use your password instead.');
        }

        $existing = User::where('email', $email)->first();

        if ($existing) {
            // The address matches a person here. Join them only when the provider vouches for the address.
            if (! $this->verified($provider, $outside)) {
                throw new StockException('An account with this email already exists. Sign in with your password, then connect it in Settings.');
            }

            $this->mustBeAllowed($existing);
            $this->link($existing, $provider, $outside);
            $this->activity->record('security.social_linked', $existing, ['provider' => $provider, 'how' => 'verified email'], $existing);

            return ['user' => $existing, 'created' => false];
        }

        // Made by signing in with Google or Facebook: the password is random and nobody knows it, and the email is only trusted if the provider vouched for it.
        $user = (new User)->forceFill([
            'name' => mb_substr(trim((string) $outside->getName()) ?: Str::before($email, '@'), 0, 120), 'email' => $email,
            'password' => Str::random(48), 'account_type' => AccountType::Public, 'has_password' => false,
            'email_verified_at' => $this->verified($provider, $outside) ? now() : null,
        ]);
        $user->save();
        $this->link($user, $provider, $outside);
        $this->activity->record('security.social_signup', $user, ['provider' => $provider], $user);

        return ['user' => $user, 'created' => true];
    }

    /** A signed-in person joins an outside account to themselves (Settings). @throws StockException */
    public function connect(User $user, string $provider): void
    {
        $this->configure($provider);
        $outside = Socialite::driver($provider)->user();

        if (! $outside->getId()) {
            throw new StockException('The sign-in did not work. Please try again.');
        }

        $other = SocialAccount::where('provider', $provider)->where('provider_id', (string) $outside->getId())->first();

        if ($other && $other->user_id !== $user->id) {
            throw new StockException('That account is already joined to another person here.');
        }

        $this->mustBeAllowed($user);
        $this->link($user, $provider, $outside);
        $this->activity->record('security.social_linked', $user, ['provider' => $provider, 'how' => 'signed in'], $user);
    }

    /** Take an outside account off a person. Someone who has no password of their own must set one first, or they would lock themselves out. @throws StockException */
    public function disconnect(User $user, string $provider): void
    {
        $account = SocialAccount::where('user_id', $user->id)->where('provider', $provider)->first();

        if (! $account) {
            return;
        }

        if ($user->has_password === false && SocialAccount::where('user_id', $user->id)->count() <= 1) {
            throw new StockException('Set a password first. Otherwise you could not sign in any more.');
        }

        $account->delete();
        $this->activity->record('security.social_unlinked', $user, ['provider' => $provider], $user);
    }

    /** @return array<int, array{provider: string, name: string, email: ?string}> */
    public function linked(User $user): array
    {
        return SocialAccount::where('user_id', $user->id)->get()->map(fn (SocialAccount $a) => ['provider' => $a->provider, 'name' => self::PROVIDERS[$a->provider] ?? $a->provider, 'email' => $a->email])->all();
    }

    // ------------------------------------------------------------------ helpers

    /** Hand the keys the owner saved to Socialite, only for this request. @throws StockException */
    private function configure(string $provider): void
    {
        $on = isset(self::PROVIDERS[$provider]) ? $this->integrations->active("social:{$provider}") : null;

        if (! $on) {
            throw new StockException('This way of signing in is not switched on.');
        }

        // The secret part is encrypted, the rest (client id, app id) is a plain setting; both were saved from the same screen.
        $c = $on['credentials'] + $on['settings'];
        config(["services.{$provider}" => [
            'client_id' => $provider === 'google' ? ($c['client_id'] ?? '') : ($c['app_id'] ?? ''),
            'client_secret' => $provider === 'google' ? ($c['client_secret'] ?? '') : ($c['app_secret'] ?? ''),
            'redirect' => route('social.callback', $provider),
        ]]);
    }

    /** Does the provider say the email address is checked? Google does, in its answer; Facebook's is never taken as proof. */
    private function verified(string $provider, SocialUser $outside): bool
    {
        if ($provider !== 'google') {
            return false;
        }

        $raw = (array) ($outside->user ?? []);

        return filter_var($raw['email_verified'] ?? $raw['verified_email'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /** @throws StockException */
    private function mustBeAllowed(User $user): void
    {
        if ($user->account_type !== AccountType::Public) {
            throw new StockException('This account signs in with its password. Please use the email and password form.');
        }

        if ($user->isSuspended()) {
            throw new StockException('This account is closed.');
        }
    }

    private function link(User $user, string $provider, SocialUser $outside): void
    {
        SocialAccount::updateOrCreate(['user_id' => $user->id, 'provider' => $provider], ['provider_id' => (string) $outside->getId(), 'email' => $outside->getEmail()]);
    }
}
