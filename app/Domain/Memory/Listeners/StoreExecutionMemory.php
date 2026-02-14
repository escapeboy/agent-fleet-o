<?php

namespace App\Domain\Memory\Listeners;

use App\Domain\Agent\Models\AgentExecution;
use App\Domain\Memory\Actions\StoreMemoryAction;
use Illuminate\Support\Facades\Log;

class StoreExecutionMemory
{
    public function __construct(
        private readonly StoreMemoryAction $storeMemory,
    ) {}

    /**
     * Store memories from a completed agent execution.
     *
     * Listens for AgentExecution creation events where status is 'completed'.
     */
    public function handle(AgentExecution $execution): void
    {
        if ($execution->status !== 'completed') {
            return;
        }

        if (! config('memory.enabled', true)) {
            return;
        }

        $output = $execution->output;
        if (empty($output)) {
            return;
        }

        $content = is_array($output) ? ($output['result'] ?? json_encode($output)) : (string) $output;

        if (empty(trim($content))) {
            return;
        }

        try {
            $this->storeMemory->execute(
                teamId: $execution->team_id,
                agentId: $execution->agent_id,
                content: $content,
                sourceType: 'execution',
                projectId: $execution->experiment?->project_id ?? null,
                sourceId: $execution->id,
                metadata: [
                    'execution_id' => $execution->id,
                    'experiment_id' => $execution->experiment_id,
                    'cost_credits' => $execution->cost_credits,
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('StoreExecutionMemory: Failed to store execution memory', [
                'execution_id' => $execution->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
