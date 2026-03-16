<?php

namespace App\Livewire\Profile;

use Livewire\Component;

class ProfilePage extends Component
{
    public function render()
    {
        return view('livewire.profile.profile-page')
            ->layout('layouts.app', ['header' => 'Profile Settings']);
    }
}
