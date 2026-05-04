<?php

namespace App\Jobs;

use App\Domain\Shared\Models\Team;
use App\Domain\Workflow\Actions\ImportWorkflowAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Import a workflow YAML file fetched from a GitHub PR-merged event.
 *
 * Idempotent — ImportWorkflowAction handles dedup on workflow slug
 * (existing workflow with same slug is updated, not duplicated).
 */
class ImportWorkflowFromYamlJob implements ShouldQueue
{
    use FoundationQueueable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public string $teamId,
        public string $userId,
        public string $yaml,
        public string $sourceRef,
    ) {}

    public function handle(ImportWorkflowAction $action): void
    {
        $team = Team::withoutGlobalScopes()->find($this->teamId);
        if (! $team) {
            Log::warning('ImportWorkflowFromYamlJob: team not found', ['team_id' => $this->teamId]);

            return;
        }

        try {
            $result = $action->execute(
                data: $this->yaml,
                teamId: $this->teamId,
                userId: $this->userId,
            );
            Log::info('ImportWorkflowFromYamlJob: imported', [
                'team_id' => $this->teamId,
                'workflow_id' => $result['workflow']->id,
                'source_ref' => $this->sourceRef,
            ]);
        } catch (\Throwable $e) {
            Log::error('ImportWorkflowFromYamlJob: import failed', [
                'team_id' => $this->teamId,
                'source_ref' => $this->sourceRef,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
