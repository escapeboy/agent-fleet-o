<?php

namespace App\Livewire\Profile;

use App\Actions\Fortify\UpdateUserPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Livewire\Component;

class UpdatePasswordForm extends Component
{
    public bool $hasPassword;

    public string $currentPassword = '';

    public string $password = '';

    public string $passwordConfirmation = '';

    public function mount(): void
    {
        $this->hasPassword = auth()->user()->password !== null;
    }

    /** Change an existing password via the Fortify action. */
    public function updatePassword(UpdateUserPassword $updater): void
    {
        $updater->update(auth()->user(), [
            'current_password' => $this->currentPassword,
            'password' => $this->password,
            'password_confirmation' => $this->passwordConfirmation,
        ]);

        $this->reset('currentPassword', 'password', 'passwordConfirmation');
        session()->flash('password_saved', true);
    }

    /** Set a password for the first time (social-only accounts where password is null). */
    public function setInitialPassword(): void
    {
        $data = Validator::make([
            'password' => $this->password,
            'password_confirmation' => $this->passwordConfirmation,
        ], [
            'password' => ['required', 'string', Password::default(), 'confirmed'],
        ])->validate();

        auth()->user()->forceFill([
            'password' => Hash::make($data['password']),
        ])->save();

        $this->hasPassword = true;
        $this->reset('password', 'passwordConfirmation');
        session()->flash('password_saved', true);
    }

    public function render()
    {
        return view('livewire.profile.update-password-form');
    }
}
