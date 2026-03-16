<?php

namespace App\Http\Controllers;

use App\Domain\Shared\Notifications\SocialMergeOtpNotification;
use App\Domain\Shared\Services\SocialAccountService;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthController extends Controller
{
    private const SUPPORTED_PROVIDERS = ['google', 'github', 'linkedin-openid', 'x', 'apple'];

    /** OTP validity window in minutes. */
    private const OTP_TTL_MINUTES = 10;

    public function __construct(private readonly SocialAccountService $socialAccountService) {}

    public function redirect(string $provider): RedirectResponse
    {
        $this->validateProvider($provider);

        $driver = Socialite::driver($provider);

        // Enable PKCE for providers that support it (X has it on by default)
        if (in_array($provider, ['google', 'linkedin-openid', 'apple'], true)) {
            $driver->enablePKCE();
        }

        return $driver->redirect();
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        $this->validateProvider($provider);

        try {
            $driver = Socialite::driver($provider);

            if (in_array($provider, ['google', 'linkedin-openid', 'apple'], true)) {
                $driver->enablePKCE();
            }

            $socialUser = $driver->user();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Social login callback failed', [
                'provider'  => $provider,
                'exception' => $e->getMessage(),
                'class'     => get_class($e),
            ]);

            return redirect()->route('login')
                ->withErrors(['social' => 'Authentication failed. Please try again.']);
        }

        return $this->processCallback($request, $provider, $socialUser);
    }

    /**
     * Apple uses response_mode=form_post — callback arrives as POST.
     * Route must bypass CSRF verification.
     */
    public function appleCallback(Request $request): RedirectResponse
    {
        try {
            $socialUser = Socialite::driver('apple')->user();
        } catch (\Throwable $e) {
            return redirect()->route('login')
                ->withErrors(['social' => 'Apple Sign In failed. Please try again.']);
        }

        return $this->processCallback($request, 'apple', $socialUser);
    }

    public function linkRedirect(string $provider): RedirectResponse
    {
        $this->validateProvider($provider);

        $driver = Socialite::driver($provider);

        if (in_array($provider, ['google', 'linkedin-openid', 'apple'], true)) {
            $driver->enablePKCE();
        }

        return $driver->redirect();
    }

    public function unlink(string $provider): RedirectResponse
    {
        $this->validateProvider($provider);

        $success = $this->socialAccountService->unlink(Auth::user(), $provider);

        if (! $success) {
            return redirect()->route('team.settings')
                ->withErrors(['social' => 'Cannot disconnect your only login method. Set a password first.']);
        }

        return redirect()->route('team.settings')
            ->with('status', ucfirst($provider).' account disconnected.');
    }

    public function storeEmail(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email', 'max:255']]);

        // Guard: email already registered — do not auto-link without verification.
        // The email here is user-supplied and unverified (the provider returned none).
        // Linking to an existing account without ownership proof would allow account takeover.
        if (User::where('email', $request->input('email'))->exists()) {
            return back()->withErrors(['email' => 'An account with this email already exists. Please log in and connect your social account from Settings.']);
        }

        $user = $this->socialAccountService->completePendingRegistration($request->input('email'));

        if (! $user) {
            return redirect()->route('login')
                ->withErrors(['social' => 'Session expired. Please try again.']);
        }

        // Regenerate session to prevent session fixation after privilege change.
        $request->session()->regenerate();
        Auth::login($user, remember: true);

        return redirect()->intended(route('dashboard'));
    }

    /**
     * Initiate merge: generate OTP and email it to the address in the pending session.
     * The actual account link is completed only after OTP verification.
     */
    public function doMerge(Request $request): RedirectResponse
    {
        $pending = session('pending_social_link');

        if (! $pending) {
            return redirect()->route('login')
                ->withErrors(['social' => 'Session expired. Please try again.']);
        }

        $existingUser = User::where('email', $pending['email'])->first();

        if (! $existingUser) {
            session()->forget('pending_social_link');

            return redirect()->route('login')
                ->withErrors(['social' => 'Account not found. Please try again.']);
        }

        // Generate a 6-digit OTP, store it with an expiry in the session.
        $otp     = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires = now()->addMinutes(self::OTP_TTL_MINUTES);

        $pending['otp']            = $otp;
        $pending['otp_expires_at'] = $expires->toIso8601String();
        session(['pending_social_link' => $pending]);

        $existingUser->notify(new SocialMergeOtpNotification($otp, ucfirst($pending['provider'])));

        return redirect()->route('auth.social.verify-merge');
    }

    /**
     * Verify the OTP sent to the existing account's email and complete the link.
     */
    public function verifyMerge(Request $request): RedirectResponse
    {
        $request->validate(['otp' => ['required', 'string', 'digits:6']]);

        $pending = session('pending_social_link');

        if (! $pending || ! isset($pending['otp'], $pending['otp_expires_at'])) {
            return redirect()->route('login')
                ->withErrors(['social' => 'Session expired. Please try again.']);
        }

        // Check expiry.
        if (now()->isAfter($pending['otp_expires_at'])) {
            session()->forget('pending_social_link');

            return redirect()->route('login')
                ->withErrors(['social' => 'Verification code expired. Please start again.']);
        }

        // Constant-time comparison to prevent timing attacks.
        if (! hash_equals($pending['otp'], $request->input('otp'))) {
            return back()->withErrors(['otp' => 'Invalid verification code. Please try again.']);
        }

        $user = $this->socialAccountService->confirmMerge();

        if (! $user) {
            return redirect()->route('login')
                ->withErrors(['social' => 'Something went wrong. Please try again.']);
        }

        // Regenerate session to prevent session fixation after privilege change.
        $request->session()->regenerate();
        Auth::login($user, remember: true);

        return redirect()->intended(route('dashboard'));
    }

    private function processCallback(Request $request, string $provider, \Laravel\Socialite\Contracts\User $socialUser): RedirectResponse
    {
        $result = $this->socialAccountService->handleCallback($provider, $socialUser);

        if ($result['redirect']) {
            return redirect($result['redirect']);
        }

        if ($result['user']) {
            // Regenerate session to prevent session fixation after privilege change.
            $request->session()->regenerate();
            Auth::login($result['user'], remember: true);

            return redirect()->intended(route('dashboard'));
        }

        return redirect()->route('login')
            ->withErrors(['social' => 'Something went wrong. Please try again.']);
    }

    private function validateProvider(string $provider): void
    {
        if (! in_array($provider, self::SUPPORTED_PROVIDERS, true)) {
            abort(404);
        }
    }
}
