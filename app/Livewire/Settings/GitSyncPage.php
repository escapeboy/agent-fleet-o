<?php

namespace App\Livewire\Settings;

use App\Domain\GitRepository\Actions\CreateContextGitSyncAction;
use App\Domain\GitRepository\Actions\DeleteContextGitSyncAction;
use App\Domain\GitRepository\Jobs\PushContextToGitJob;
use App\Domain\GitRepository\Models\ContextGitSync;
use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\Workflow\Actions\CreateWorkflowGitSyncAction;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowGitSync;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;

/**
 * Settings page for Git sync — configures the team-level context filesystem
 * sync and manages per-workflow YAML syncs. Kanwas-inspired sprint.
 */
class GitSyncPage extends Component
{
    public ?string $selectedRepoId = null;

    public string $branch = 'fleetq-context';

    public bool $syncArtifacts = true;

    public bool $syncMemory = true;

    public ?string $workflowSyncWorkflowId = null;

    public ?string $workflowSyncRepoId = null;

    public function mount(): void
    {
        $sync = ContextGitSync::where('team_id', $this->teamId())->first();

        if ($sync) {
            $this->selectedRepoId = $sync->git_repository_id;
            $this->branch = $sync->branch;
            $this->syncArtifacts = $sync->sync_artifacts;
            $this->syncMemory = $sync->sync_memory;
        }
    }

    private function teamId(): ?string
    {
        return auth()->user()?->current_team_id;
    }

    public function saveContextSync(): void
    {
        Gate::authorize('edit-content');

        $this->validate([
            'selectedRepoId' => 'required|string',
            'branch' => 'required|string|max:255',
        ]);

        app(CreateContextGitSyncAction::class)->execute(
            teamId: $this->teamId(),
            gitRepositoryId: $this->selectedRepoId,
            branch: $this->branch,
            syncArtifacts: $this->syncArtifacts,
            syncMemory: $this->syncMemory,
        );

        session()->flash('message', 'Context Git sync saved.');
    }

    public function removeContextSync(): void
    {
        Gate::authorize('edit-content');

        app(DeleteContextGitSyncAction::class)->execute($this->teamId());

        $this->reset(['selectedRepoId', 'syncArtifacts', 'syncMemory']);
        $this->branch = 'fleetq-context';

        session()->flash('message', 'Context Git sync removed.');
    }

    public function exportNow(): void
    {
        Gate::authorize('edit-content');

        $sync = ContextGitSync::where('team_id', $this->teamId())->first();

        if (! $sync) {
            session()->flash('message', 'Configure a context sync first.');

            return;
        }

        $key = 'ctx-git-export:'.$this->teamId();

        if (RateLimiter::tooManyAttempts($key, 3)) {
            session()->flash('message', 'Too many exports. Wait '.RateLimiter::availableIn($key).'s.');

            return;
        }

        RateLimiter::hit($key, 60);
        PushContextToGitJob::dispatch($sync->id);

        session()->flash('message', 'Context export queued.');
    }

    public function createWorkflowSync(): void
    {
        Gate::authorize('edit-content');

        $this->validate([
            'workflowSyncWorkflowId' => 'required|string',
            'workflowSyncRepoId' => 'required|string',
        ]);

        app(CreateWorkflowGitSyncAction::class)->execute(
            workflowId: $this->workflowSyncWorkflowId,
            gitRepositoryId: $this->workflowSyncRepoId,
            teamId: $this->teamId(),
        );

        $this->reset(['workflowSyncWorkflowId', 'workflowSyncRepoId']);

        session()->flash('message', 'Workflow Git sync linked.');
    }

    public function removeWorkflowSync(string $id): void
    {
        Gate::authorize('edit-content');

        WorkflowGitSync::where('team_id', $this->teamId())
            ->where('id', $id)
            ->delete();

        session()->flash('message', 'Workflow Git sync removed.');
    }

    public function render()
    {
        $teamId = $this->teamId();

        $repos = GitRepository::where('team_id', $teamId)->orderBy('name')->get(['id', 'name']);
        $workflows = Workflow::where('team_id', $teamId)->orderBy('name')->get(['id', 'name']);
        $contextSync = ContextGitSync::where('team_id', $teamId)->first();
        $workflowSyncs = WorkflowGitSync::where('team_id', $teamId)
            ->with(['workflow:id,name', 'gitRepository:id,name'])
            ->get();

        return view('livewire.settings.git-sync-page', [
            'repos' => $repos,
            'workflows' => $workflows,
            'contextSync' => $contextSync,
            'workflowSyncs' => $workflowSyncs,
        ])->layout('layouts.app', ['header' => 'Git Sync']);
    }
}
