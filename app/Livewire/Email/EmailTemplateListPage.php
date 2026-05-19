<?php

namespace App\Livewire\Email;

use App\Domain\Email\Actions\CreateEmailTemplateAction;
use App\Domain\Email\Actions\DeleteEmailTemplateAction;
use App\Domain\Email\Enums\EmailTemplateStatus;
use App\Domain\Email\Models\EmailTemplate;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class EmailTemplateListPage extends Component
{
    use AuthorizesRequests;
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
        $this->authorize('edit-content');

        $this->validate(['newName' => 'required|min:2|max:255']);

        $team = auth()->user()->currentTeam;

        $template = app(CreateEmailTemplateAction::class)->execute($team, [
            'name' => $this->newName,
        ]);

        $this->showCreateModal = false;
        $this->redirect(route('email.templates.edit', $template));
    }

    public function delete(string $id): void
    {
        $this->authorize('edit-content');

        $template = EmailTemplate::findOrFail($id);

        app(DeleteEmailTemplateAction::class)->execute($template);

        session()->flash('message', 'Template deleted.');
    }

    public function render()
    {
        $query = EmailTemplate::query();

        if ($this->search) {
            $query->where('name', 'ilike', "%{$this->search}%");
        }

        if ($this->statusFilter) {
            $query->where('status', $this->statusFilter);
        }

        $query->orderByDesc('created_at');

        return view('livewire.email.email-template-list-page', [
            'templates' => $query->paginate(20),
            'statuses' => EmailTemplateStatus::cases(),
        ])->layout('layouts.app', ['header' => 'Email Templates']);
    }
}
