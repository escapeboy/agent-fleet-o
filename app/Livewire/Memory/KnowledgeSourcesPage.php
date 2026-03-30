<?php

namespace App\Livewire\Memory;

use App\Domain\Agent\Models\Agent;
use App\Domain\Knowledge\Actions\CreateKnowledgeBaseAction;
use App\Domain\Knowledge\Actions\DeleteKnowledgeBaseAction;
use App\Domain\Knowledge\Actions\IngestDocumentAction;
use App\Domain\Knowledge\Models\KnowledgeBase;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class KnowledgeSourcesPage extends Component
{
    public bool $showCreateModal = false;

    public string $createName = '';

    public string $createDescription = '';

    public ?string $createAgentId = null;

    public ?string $ingestKbId = null;

    public string $ingestContent = '';

    public string $ingestSourceName = '';

    public function openCreate(): void
    {
        $this->reset(['createName', 'createDescription', 'createAgentId']);
        $this->showCreateModal = true;
    }

    public function create(CreateKnowledgeBaseAction $action): void
    {
        $this->validate([
            'createName' => 'required|string|min:2|max:255',
            'createDescription' => 'nullable|string|max:1000',
            'createAgentId' => 'nullable|uuid',
        ]);

        $action->execute(
            teamId: auth()->user()->current_team_id,
            name: $this->createName,
            description: $this->createDescription ?: null,
            agentId: $this->createAgentId ?: null,
        );

        $this->showCreateModal = false;
        session()->flash('message', 'Knowledge base created.');
    }

    public function delete(string $kbId, DeleteKnowledgeBaseAction $action): void
    {
        $kb = KnowledgeBase::findOrFail($kbId);
        $action->execute($kb);
        session()->flash('message', 'Knowledge base deleted.');
    }

    public function openIngest(string $kbId): void
    {
        $this->ingestKbId = $kbId;
        $this->ingestContent = '';
        $this->ingestSourceName = '';
    }

    public function ingest(IngestDocumentAction $action): void
    {
        $this->validate([
            'ingestContent' => 'required|string|min:10',
            'ingestSourceName' => 'nullable|string|max:255',
        ]);

        $kb = KnowledgeBase::findOrFail($this->ingestKbId);

        $action->execute(
            knowledgeBase: $kb,
            content: $this->ingestContent,
            sourceName: $this->ingestSourceName ?: 'manual',
            sourceType: 'text',
        );

        $this->ingestKbId = null;
        session()->flash('message', 'Document queued for ingestion.');
    }

    public function render(): View
    {
        $teamId = auth()->user()->current_team_id;

        $knowledgeBases = KnowledgeBase::where('team_id', $teamId)
            ->with('agent')
            ->orderByDesc('created_at')
            ->get();

        $agents = Agent::where('team_id', $teamId)
            ->where('status', 'active')
            ->orderBy('name')
            ->get();

        return view('livewire.memory.knowledge-sources-page', [
            'knowledgeBases' => $knowledgeBases,
            'agents' => $agents,
        ])->layout('layouts.app', ['header' => 'Knowledge Sources']);
    }
}
