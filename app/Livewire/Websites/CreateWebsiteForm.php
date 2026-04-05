<?php

namespace App\Livewire\Websites;

use App\Domain\Website\Actions\CreateWebsiteAction;
use App\Domain\Website\Actions\GenerateWebsiteWithCrewAction;
use Livewire\Component;

class CreateWebsiteForm extends Component
{
    public string $name = '';

    public string $slug = '';

    public string $mode = 'manual'; // manual | ai

    public string $prompt = '';

    public bool $generating = false;

    public function updatedName(): void
    {
        if (! $this->slug || $this->slug === $this->slugify($this->name)) {
            $this->slug = $this->slugify($this->name);
        }
    }

    public function create(CreateWebsiteAction $action): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9-]*$/'],
        ]);

        $website = $action->execute(
            teamId: auth()->user()->current_team_id,
            name: $this->name,
            data: ['slug' => $this->slug ?: null],
        );

        $this->redirectRoute('websites.show', $website);
    }

    public function generate(GenerateWebsiteWithCrewAction $action): void
    {
        $this->validate([
            'prompt' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $this->generating = true;

        $website = $action->execute(
            teamId: auth()->user()->current_team_id,
            prompt: $this->prompt,
        );

        $this->redirectRoute('websites.index');
    }

    public function render()
    {
        return view('livewire.websites.create-website-form')
            ->layout('layouts.app', ['header' => 'New Website']);
    }

    private function slugify(string $value): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '-', $value) ?? '');
    }
}
