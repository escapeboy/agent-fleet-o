<?php

declare(strict_types=1);

namespace App\Livewire\Releases;

use App\Domain\Release\Actions\CreateReleaseAction;
use App\Domain\Release\Models\Release;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Livewire\Component;

class ReleaseListPage extends Component
{
    public string $newName = '';

    public string $newVersion = '';

    public string $newNotes = '';

    public bool $creating = false;

    public function startCreate(): void
    {
        Gate::authorize('edit-content');
        $this->creating = true;
    }

    public function cancelCreate(): void
    {
        $this->creating = false;
        $this->newName = '';
        $this->newVersion = '';
        $this->newNotes = '';
        $this->resetValidation();
    }

    public function create(CreateReleaseAction $action): void
    {
        Gate::authorize('edit-content');

        $this->validate([
            'newName' => 'required|string|min:1|max:255',
            'newVersion' => 'required|string|min:1|max:64',
            'newNotes' => 'nullable|string|max:5000',
        ]);

        try {
            $release = $action->execute(
                teamId: auth()->user()->current_team_id,
                userId: auth()->id(),
                name: $this->newName,
                version: $this->newVersion,
                notes: $this->newNotes ?: null,
            );
        } catch (InvalidArgumentException $e) {
            $this->addError('newName', $e->getMessage());

            return;
        }

        $this->cancelCreate();
        $this->redirect(route('releases.show', $release), navigate: true);
    }

    public function render()
    {
        $releases = Release::orderByDesc('created_at')->paginate(20);

        return view('livewire.releases.release-list-page', [
            'releases' => $releases,
        ])->layout('layouts.app', ['header' => 'Releases']);
    }
}
