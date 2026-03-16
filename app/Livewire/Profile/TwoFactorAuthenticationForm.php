<?php

namespace App\Livewire\Profile;

use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Livewire\Component;

class TwoFactorAuthenticationForm extends Component
{
    /** 'disabled' | 'enabling' | 'enabled' */
    public string $state = 'disabled';

    public bool $showingRecoveryCodes = false;
    public string $confirmationCode = '';
    public string $confirmationError = '';

    public function mount(): void
    {
        $user = auth()->user();

        if ($user->two_factor_secret && $user->two_factor_confirmed_at) {
            $this->state = 'enabled';
        } elseif ($user->two_factor_secret) {
            $this->state = 'enabling';
        } else {
            $this->state = 'disabled';
        }
    }

    public function enableTwoFactor(): void
    {
        app(EnableTwoFactorAuthentication::class)(auth()->user());
        $this->state = 'enabling';
        $this->confirmationCode = '';
        $this->confirmationError = '';
    }

    public function confirmTwoFactor(): void
    {
        $this->confirmationError = '';

        try {
            app(ConfirmTwoFactorAuthentication::class)(auth()->user(), $this->confirmationCode);
            $this->state = 'enabled';
            $this->showingRecoveryCodes = true;
            $this->confirmationCode = '';
        } catch (\Exception) {
            $this->confirmationError = 'The code was invalid. Please try again.';
        }
    }

    public function disableTwoFactor(): void
    {
        app(DisableTwoFactorAuthentication::class)(auth()->user());
        $this->state = 'disabled';
        $this->showingRecoveryCodes = false;
    }

    public function regenerateRecoveryCodes(): void
    {
        app(GenerateNewRecoveryCodes::class)(auth()->user());
        $this->showingRecoveryCodes = true;
    }

    public function getQrCodeSvgProperty(): ?string
    {
        $user = auth()->user();

        if ($this->state === 'enabling' && method_exists($user, 'twoFactorQrCodeSvg')) {
            return $user->twoFactorQrCodeSvg();
        }

        return null;
    }

    public function getRecoveryCodesProperty(): array
    {
        $user = auth()->user();

        if (! $this->showingRecoveryCodes || ! method_exists($user, 'recoveryCodes')) {
            return [];
        }

        return $user->recoveryCodes();
    }

    public function render()
    {
        return view('livewire.profile.two-factor-authentication-form', [
            'qrCodeSvg' => $this->qrCodeSvg,
            'recoveryCodes' => $this->recoveryCodes,
        ]);
    }
}
