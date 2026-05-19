<?php

namespace App\Livewire\Audiences;

use App\Domain\Audience\Actions\CreateAudience;
use App\Domain\Audience\Models\Audience;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\WithPagination;

class AudienceListPage extends Component
{
    use WithPagination;

    public string $name = '';

    public string $topic = '';

    public string $description = '';

    public function create(): void
    {
        Gate::authorize('edit-content');

        $this->validate([
            'name' => 'required|string|max:255',
            'topic' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        app(CreateAudience::class)->execute(
            teamId: auth()->user()->currentTeam->id,
            name: $this->name,
            description: $this->description ?: null,
            topic: $this->topic ?: null,
        );

        $this->reset(['name', 'topic', 'description']);
        session()->flash('message', 'Audience created.');
    }

    public function render()
    {
        return view('livewire.audiences.audience-list-page', [
            'audiences' => Audience::query()
                ->withCount(['members', 'subscribedMembers'])
                ->orderBy('name')
                ->paginate(20),
        ])->layout('layouts.app', ['header' => 'Audiences']);
    }
}
