<?php

namespace App\Livewire\Profile;

use App\Actions\Fortify\UpdateUserProfileInformation;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class UpdateProfileInformationForm extends Component
{
    public string $name = '';

    public string $email = '';

    public bool $emailChanged = false;

    public function mount(): void
    {
        $user = auth()->user();
        $this->name = $user->name;
        $this->email = $user->email;
    }

    public function save(UpdateUserProfileInformation $updater): void
    {
        Gate::authorize('update-self');

        $user = auth()->user();
        $oldEmail = $user->email;

        $updater->update($user, [
            'name' => $this->name,
            'email' => $this->email,
        ]);

        $this->emailChanged = $user instanceof MustVerifyEmail && $this->email !== $oldEmail;

        session()->flash('profile_saved', true);
    }

    public function render()
    {
        return view('livewire.profile.update-profile-information-form');
    }
}
