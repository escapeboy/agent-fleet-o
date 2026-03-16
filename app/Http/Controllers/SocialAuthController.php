<?php

namespace App\Http\Controllers;

use App\Domain\Shared\Services\SocialAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthController extends Controller
{
    private const SUPPORTED_PROVIDERS = ['google', 'github', 'linkedin-openid', 'x', 'apple'];

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

    public function callback(string $provider): RedirectResponse
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
                'provider' => $provider,
                'exception' => $e->getMessage(),
                'class' => get_class($e),
            ]);

            return redirect()->route('login')
                ->withErrors(['social' => 'Authentication failed. Please try again.']);
        }

        return $this->processCallback($provider, $socialUser);
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

        return $this->processCallback('apple', $socialUser);
    }

    public function linkRedirect(string $provider): RedirectResponse
    {
        $this->validateProvider($provider);

        session(['socialite_link_intent' => $provider]);

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
        if (\App\Models\User::where('email', $request->input('email'))->exists()) {
            return back()->withErrors(['email' => 'An account with this email already exists. Please log in and connect your social account from Settings.']);
        }

        $user = $this->socialAccountService->completePendingRegistration($request->input('email'));

        if (! $user) {
            return redirect()->route('login')
                ->withErrors(['social' => 'Session expired. Please try again.']);
        }

        Auth::login($user, remember: true);

        return redirect()->intended(route('dashboard'));
    }

    public function doMerge(): RedirectResponse
    {
        $user = $this->socialAccountService->confirmMerge();

        if (! $user) {
            return redirect()->route('login')
                ->withErrors(['social' => 'Session expired. Please try again.']);
        }

        Auth::login($user, remember: true);

        return redirect()->intended(route('dashboard'));
    }

    private function processCallback(string $provider, \Laravel\Socialite\Contracts\User $socialUser): RedirectResponse
    {
        $result = $this->socialAccountService->handleCallback($provider, $socialUser);

        if ($result['redirect']) {
            return redirect($result['redirect']);
        }

        if ($result['user']) {
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
