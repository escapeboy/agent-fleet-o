<?php

namespace App\Livewire\Email;

use App\Domain\Email\Actions\CreateEmailThemeAction;
use App\Domain\Email\Enums\EmailThemeStatus;
use App\Domain\Email\Models\EmailTheme;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class EmailThemeListPage extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $statusFilter = '';

    public bool $showCreateModal = false;

    public string $newName = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function openCreateModal(): void
    {
        $this->newName = '';
        $this->showCreateModal = true;
    }

    public function create(): void
    {
        $this->validate(['newName' => 'required|min:2|max:255']);

        $team = auth()->user()->currentTeam;

        $theme = app(CreateEmailThemeAction::class)->execute($team, [
            'name' => $this->newName,
        ]);

        $this->showCreateModal = false;
        $this->redirect(route('email.themes.show', $theme));
    }

    public function render()
    {
        $query = EmailTheme::query();

        if ($this->search) {
            $query->where('name', 'ilike', "%{$this->search}%");
        }

        if ($this->statusFilter) {
            $query->where('status', $this->statusFilter);
        }

        $query->orderByDesc('created_at');

        return view('livewire.email.email-theme-list-page', [
            'themes' => $query->paginate(20),
            'statuses' => EmailThemeStatus::cases(),
        ])->layout('layouts.app', ['header' => 'Email Themes']);
    }
}
