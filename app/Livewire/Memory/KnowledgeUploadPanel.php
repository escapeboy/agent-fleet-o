<?php

namespace App\Livewire\Memory;

use App\Domain\Memory\Actions\UploadKnowledgeDocumentAction;
use Livewire\Component;
use Livewire\WithFileUploads;

class KnowledgeUploadPanel extends Component
{
    use WithFileUploads;

    public ?string $agentId = null;

    public ?string $projectId = null;

    public $file;

    public ?string $error = null;

    public ?string $success = null;

    public function upload(): void
    {
        $this->validate([
            'file' => 'required|file|mimes:pdf,txt,md,csv|max:10240',
        ]);

        $this->error = null;
        $this->success = null;

        try {
            $action = app(UploadKnowledgeDocumentAction::class);
            $memories = $action->execute(
                teamId: auth()->user()->current_team_id,
                agentId: $this->agentId,
                file: $this->file,
                projectId: $this->projectId,
            );

            $this->success = count($memories).' memory chunks created from '.$this->file->getClientOriginalName();
            $this->reset('file');
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function render()
    {
        return view('livewire.memory.knowledge-upload-panel');
    }
}
