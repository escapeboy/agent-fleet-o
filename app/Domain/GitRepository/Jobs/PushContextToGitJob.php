<?php

namespace App\Domain\GitRepository\Jobs;

use App\Domain\GitRepository\Models\ContextGitSync;
use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\GitRepository\Services\ContextMarkdownRenderer;
use App\Domain\GitRepository\Services\GitOperationRouter;
use App\Domain\Memory\Models\Memory;
use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Models\UserNotification;
use App\Models\Artifact;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Push a team's accumulated context (artifacts + memory) to its linked
 * GitRepository as a versioned markdown filesystem. Kanwas-inspired sprint.
 *
 * One-way only (FleetQ → Git). Full-tree re-export each push — simple and
 * idempotent; row caps bound the cost. Failure surfaces a UserNotification
 * after retries are exhausted.
 */
class PushContextToGitJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 90];

    private const ARTIFACT_CAP = 500;

    private const MEMORY_CAP = 1000;

    public function __construct(public readonly string $syncId) {}

    /**
     * Serialize pushes per sync — two concurrent full-tree exports to the same
     * branch would race the remote and overwrite each other's commit SHA.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->syncId))->releaseAfter(30)->expireAfter(600)];
    }

    public function handle(GitOperationRouter $router, ContextMarkdownRenderer $renderer): void
    {
        /** @var ContextGitSync|null $sync */
        $sync = ContextGitSync::withoutGlobalScopes()->find($this->syncId);
        if (! $sync) {
            return;
        }

        $repo = GitRepository::withoutGlobalScopes()->find($sync->git_repository_id);
        if (! $repo) {
            return;
        }

        $changes = [];

        if ($sync->sync_artifacts) {
            $artifacts = Artifact::withoutGlobalScopes()
                ->where('team_id', $sync->team_id)
                ->with('versions')
                ->orderByDesc('updated_at')
                ->limit(self::ARTIFACT_CAP)
                ->get();

            foreach ($artifacts as $artifact) {
                $rendered = $renderer->artifact($artifact, $sync->artifact_path_prefix);
                if ($rendered !== null) {
                    $changes[] = $rendered;
                }
            }
        }

        if ($sync->sync_memory) {
            $memories = Memory::withoutGlobalScopes()
                ->where('team_id', $sync->team_id)
                ->orderByDesc('updated_at')
                ->limit(self::MEMORY_CAP)
                ->get();

            foreach ($memories as $memory) {
                $changes[] = $renderer->memory($memory, $sync->memory_path_prefix);
            }
        }

        if ($changes === []) {
            return;
        }

        $client = $router->resolve($repo);
        $sha = $client->commit(
            $changes,
            'chore(fleetq): sync team context ('.count($changes).' files)',
            $sync->branch,
        );

        $sync->update([
            'last_pushed_sha' => $sha,
            'last_pushed_at' => now(),
        ]);
    }

    public function failed(Throwable $e): void
    {
        $sync = ContextGitSync::withoutGlobalScopes()->find($this->syncId);
        if (! $sync) {
            return;
        }

        $ownerId = Team::ownerIdFor($sync->team_id);
        if (! $ownerId) {
            return;
        }

        UserNotification::create([
            'user_id' => $ownerId,
            'team_id' => $sync->team_id,
            'type' => 'context_git_sync_failed',
            'title' => 'Context Git Sync failed',
            'body' => 'Push of team context to the linked repository failed after '
                .$this->tries.' attempts: '.$e->getMessage(),
            'data' => ['sync_id' => $sync->id, 'error' => $e->getMessage()],
        ]);
    }
}
