<?php

namespace App\Livewire\Profile;

use LaravelWebauthn\WebauthnServiceProvider;
use Livewire\Component;

class PasskeysForm extends Component
{
    public function deletePasskey(string $id): void
    {
        auth()->user()->webauthnKeys()->where('id', $id)->delete();

        session()->flash('passkey_message', 'Passkey removed.');
    }

    public function render()
    {
        return view('livewire.profile.passkeys-form', [
            'webauthnEnabled' => config('webauthn.enabled', class_exists(WebauthnServiceProvider::class)),
            'passkeys' => class_exists(WebauthnServiceProvider::class)
                ? (auth()->user()?->webauthnKeys ?? collect())
                : collect(),
        ]);
    }
}
