<?php

namespace App\Domain\Shared\Services;

use App\Domain\Shared\Models\UserSocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Contracts\User as SocialiteUser;

class SocialAccountService
{
    // All supported OAuth providers verify the user's email before returning it —
    // safe to auto-link to existing accounts. The only exception is GitHub when
    // the user has "Keep email private" enabled: getEmail() returns null and the
    // collect-email flow handles that case separately.
    private const VERIFIED_EMAIL_PROVIDERS = ['google', 'github', 'linkedin-openid', 'x', 'apple'];

    /**
     * Handle the OAuth callback and return the authenticated user,
     * or null if additional user interaction is required (email collection / merge confirm).
     * Side effects: stores pending state in session.
     *
     * @return array{user: User|null, redirect: string|null}
     */
    public function handleCallback(string $provider, SocialiteUser $socialUser): array
    {
        // 1. Existing social account → update token and log in
        $existing = UserSocialAccount::where('provider', $provider)
            ->where('provider_user_id', (string) $socialUser->getId())
            ->first();

        if ($existing) {
            $this->updateToken($existing, $socialUser);

            return ['user' => $existing->user, 'redirect' => null];
        }

        // 2. Authenticated user is connecting a new provider
        if (Auth::check()) {
            $this->attachSocialAccount(Auth::user(), $provider, $socialUser);

            return ['user' => Auth::user(), 'redirect' => route('team.settings')];
        }

        $email = $socialUser->getEmail();

        // 3. No email from provider — store pending state and collect it
        if (empty($email)) {
            session([
                'pending_social_auth' => [
                    'provider' => $provider,
                    'provider_id' => (string) $socialUser->getId(),
                    'name' => $socialUser->getName(),
                    'avatar' => $socialUser->getAvatar(),
                ],
            ]);

            return ['user' => null, 'redirect' => route('auth.social.collect-email')];
        }

        // 4. Existing user with matching email — all providers are verified, auto-link.
        $existingUser = User::where('email', $email)->first();

        if ($existingUser) {
            $this->attachSocialAccount($existingUser, $provider, $socialUser);

            return ['user' => $existingUser, 'redirect' => null];
        }

        // 5. Brand new user
        $user = User::create([
            'name' => $socialUser->getName() ?? 'User',
            'email' => $email,
            'email_verified_at' => in_array($provider, self::VERIFIED_EMAIL_PROVIDERS, true) ? now() : null,
            'password' => null,
        ]);

        $this->attachSocialAccount($user, $provider, $socialUser);

        return ['user' => $user, 'redirect' => null];
    }

    /**
     * Complete a pending social sign-up after the user provides their email.
     * Only creates a NEW account — never links to an existing account, because
     * the email is user-supplied and unverified (the provider did not return one).
     * Linking to an existing account without email verification would allow
     * account takeover; use the Settings-based link flow instead.
     */
    public function completePendingRegistration(string $email): ?User
    {
        $pending = session('pending_social_auth');
        if (! $pending) {
            return null;
        }

        // Refuse to link an unverified email to an existing account.
        if (User::where('email', $email)->exists()) {
            return null;
        }

        $user = User::create([
            'name' => $pending['name'] ?? 'User',
            'email' => $email,
            'password' => null,
        ]);

        UserSocialAccount::create([
            'user_id' => $user->id,
            'provider' => $pending['provider'],
            'provider_user_id' => $pending['provider_id'],
            'name' => $pending['name'],
            'avatar' => $pending['avatar'],
        ]);

        session()->forget('pending_social_auth');

        return $user;
    }

    public function unlink(User $user, string $provider): bool
    {
        // Safety guard: cannot remove last login method when no password is set
        if (empty($user->password)) {
            $count = $user->socialAccounts()->count();
            if ($count <= 1) {
                return false;
            }
        }

        $user->socialAccounts()->where('provider', $provider)->delete();

        return true;
    }

    private function attachSocialAccount(User $user, string $provider, SocialiteUser $socialUser): UserSocialAccount
    {
        return UserSocialAccount::updateOrCreate(
            ['provider' => $provider, 'provider_user_id' => (string) $socialUser->getId()],
            [
                'user_id' => $user->id,
                'email' => $socialUser->getEmail(),
                'name' => $socialUser->getName(),
                'avatar' => $socialUser->getAvatar(),
                'access_token' => $socialUser->token,
                'refresh_token' => $socialUser->refreshToken ?? null,
                'token_expires_at' => $socialUser->expiresIn ? now()->addSeconds($socialUser->expiresIn) : null,
                'raw_data' => $socialUser->user ?? null,
            ],
        );
    }

    private function updateToken(UserSocialAccount $account, SocialiteUser $socialUser): void
    {
        $account->update([
            'access_token' => $socialUser->token,
            'refresh_token' => $socialUser->refreshToken ?? null,
            'token_expires_at' => $socialUser->expiresIn ? now()->addSeconds($socialUser->expiresIn) : null,
        ]);
    }
}
