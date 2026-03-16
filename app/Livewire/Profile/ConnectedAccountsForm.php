<?php

namespace App\Livewire\Profile;

use App\Domain\Shared\Models\UserSocialAccount;
use Illuminate\Support\Collection;
use Livewire\Component;

class ConnectedAccountsForm extends Component
{
    public Collection $connectedAccounts;

    public array $providers = [
        ['key' => 'google',          'name' => 'Google',    'color' => 'text-red-500'],
        ['key' => 'github',          'name' => 'GitHub',    'color' => 'text-gray-800'],
        ['key' => 'linkedin-openid', 'name' => 'LinkedIn',  'color' => 'text-blue-600'],
        ['key' => 'x',               'name' => 'X',         'color' => 'text-gray-900'],
        ['key' => 'apple',           'name' => 'Apple',     'color' => 'text-gray-900'],
    ];

    public function mount(): void
    {
        $this->connectedAccounts = auth()->user()->socialAccounts()->get();
    }

    public function unlink(string $provider): void
    {
        $user = auth()->user();

        $hasPassword = $user->password !== null;
        $linkedCount = $user->socialAccounts()->count();
        $hasPasskey = method_exists($user, 'webauthnKeys') && $user->webauthnKeys()->exists();

        if (! ($hasPassword || $linkedCount > 1 || $hasPasskey)) {
            session()->flash('unlink_error', 'Cannot disconnect your only login method. Please set a password first.');

            return;
        }

        $user->socialAccounts()->where('provider', $provider)->delete();
        $this->connectedAccounts = $user->socialAccounts()->get();

        session()->flash('unlink_success', 'Account disconnected.');
    }

    public function render()
    {
        return view('livewire.profile.connected-accounts-form');
    }
}
