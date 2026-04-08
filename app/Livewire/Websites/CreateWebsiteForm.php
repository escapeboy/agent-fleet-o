<?php

namespace App\Livewire\Websites;

use App\Domain\Website\Actions\CreateWebsiteAction;
use App\Domain\Website\Actions\GenerateWebsiteFromPromptAction;
use Illuminate\Support\Str;
use Livewire\Component;

class CreateWebsiteForm extends Component
{
    public string $mode = 'blank'; // 'blank' | 'generate'

    public string $name = '';

    public string $slug = '';

    public string $customDomain = '';

    public string $prompt = '';

    public bool $generating = false;

    public function updatedName(): void
    {
        $this->slug = Str::slug($this->name);
    }

    public function submit(): void
    {
        if ($this->mode === 'generate') {
            $this->generate();

            return;
        }

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

    public function generate(): void
    {
        $this->validate([
            'name' => 'required|max:255',
            'prompt' => 'required|max:2000',
        ]);

        $this->generating = true;

        try {
            $website = app(GenerateWebsiteFromPromptAction::class)->execute(
                team: auth()->user()->currentTeam,
                prompt: $this->prompt,
                name: $this->name,
            );

            session()->flash('success', 'Website generated with AI.');
            $this->redirectRoute('websites.show', $website);
        } catch (\Throwable) {
            $this->generating = false;
            $this->addError('prompt', 'AI generation failed. Please try again or create a blank website.');
        }
    }

    public function render()
    {
        return view('livewire.websites.create-website-form')
            ->layout('layouts.app', ['header' => 'New Website']);
    }
}
