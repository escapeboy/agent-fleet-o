<?php

namespace App\Livewire\Websites;

use App\Domain\Website\Actions\CreateWebsiteAction;
use Illuminate\Support\Str;
use Livewire\Component;

class CreateWebsiteForm extends Component
{
    public string $name = '';

    public string $slug = '';

    public string $customDomain = '';

    public function updatedName(): void
    {
        $this->slug = Str::slug($this->name);
    }

    public function submit(): void
    {
        $this->validate([
            'name' => 'required|max:255',
            'slug' => 'required|max:255',
        ]);

        $website = app(CreateWebsiteAction::class)->execute(
            auth()->user()->currentTeam,
            [
                'name' => $this->name,
                'slug' => $this->slug,
                'custom_domain' => $this->customDomain ?: null,
            ],
            auth()->user(),
        );

        session()->flash('success', 'Website created.');
        $this->redirectRoute('websites.show', $website);
    }

    public function render()
    {
        return view('livewire.websites.create-website-form')
            ->layout('layouts.app', ['header' => 'New Website']);
    }
}
