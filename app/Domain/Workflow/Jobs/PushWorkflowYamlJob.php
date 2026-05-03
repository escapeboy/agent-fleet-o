<?php

namespace App\Domain\Workflow\Jobs;

use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\GitRepository\Services\GitOperationRouter;
use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Models\UserNotification;
use App\Domain\Workflow\Actions\ExportWorkflowAction;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowGitSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Push a Workflow's YAML to its linked GitRepository (build #5, Trendshift top-5).
 *
 * One-way only (FleetQ → Git). Failure surfaces a UserNotification after retries are exhausted.
 */
class PushWorkflowYamlJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 90];

    public function __construct(public readonly string $syncId) {}

    public function handle(ExportWorkflowAction $exporter, GitOperationRouter $router): void
    {
        /** @var WorkflowGitSync|null $sync */
        $sync = WorkflowGitSync::withoutGlobalScopes()->find($this->syncId);
        if (! $sync) {
            return;
        }

        $workflow = Workflow::withoutGlobalScopes()->find($sync->workflow_id);
        $repo = GitRepository::withoutGlobalScopes()->find($sync->git_repository_id);
        if (! $workflow || ! $repo) {
            return;
        }

        $yaml = $exporter->execute($workflow, format: 'yaml');
        $yamlString = is_string($yaml) ? $yaml : json_encode($yaml, JSON_PRETTY_PRINT);
        $path = $sync->path_prefix.$workflow->slug.'.yaml';

        $client = $router->resolve($repo);
        $sha = $client->writeFile(
            path: $path,
            content: (string) $yamlString,
            message: 'chore(fleetq): sync workflow "'.$workflow->name.'"',
            branch: $sync->branch,
        );

        $sync->update([
            'last_pushed_sha' => $sha,
            'last_pushed_at' => now(),
        ]);
    }

    public function failed(Throwable $e): void
    {
        $sync = WorkflowGitSync::withoutGlobalScopes()->find($this->syncId);
        if (! $sync) {
            return;
        }

        // Surface as a notification on the team owner.
        $ownerId = Team::ownerIdFor($sync->team_id);
        if (! $ownerId) {
            return;
        }

        UserNotification::create([
            'user_id' => $ownerId,
            'team_id' => $sync->team_id,
            'type' => 'workflow_git_sync_failed',
            'title' => 'Workflow Git Sync failed',
            'body' => 'Push to repo failed after '.$this->tries.' attempts: '.$e->getMessage(),
            'data' => ['sync_id' => $sync->id, 'error' => $e->getMessage()],
        ]);
    }
}
