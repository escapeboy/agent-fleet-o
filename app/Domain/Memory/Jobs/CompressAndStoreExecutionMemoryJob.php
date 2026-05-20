<?php

namespace App\Domain\Memory\Jobs;

use App\Domain\Agent\Models\AgentExecution;
use App\Domain\Memory\Actions\RetrieveRelevantMemoriesAction;
use App\Domain\Memory\Actions\StoreMemoryAction;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Services\ProviderResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CompressAndStoreExecutionMemoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(
        public readonly string $executionId,
    ) {
        $this->onQueue('memory');
    }

    public function handle(
        AiGatewayInterface $gateway,
        StoreMemoryAction $storeMemory,
        RetrieveRelevantMemoriesAction $retrieveMemories,
        ProviderResolver $resolver,
    ): void {
        $execution = AgentExecution::withoutGlobalScopes()->find($this->executionId);

        if (! $execution) {
            return;
        }

        if ($execution->status !== 'completed') {
            return;
        }

        $output = $execution->output;
        if (empty($output)) {
            return;
        }

        $rawContent = is_string($output) ? $output : json_encode($output);

        $team = $execution->team_id ? Team::withoutGlobalScopes()->find($execution->team_id) : null;
        $resolved = $resolver->resolve(team: $team);

        try {
            $response = $gateway->complete(new AiRequestDTO(
                provider: $resolved['provider'],
                model: $resolved['model'],
                systemPrompt: 'You are a memory compression assistant. Respond only with the compressed summary, no preamble.',
                userPrompt: "Summarize the key facts, decisions, and outcomes from this agent execution in 3-5 sentences for future memory retrieval.\n\n".$rawContent,
                maxTokens: 512,
                teamId: $execution->team_id,
                userId: Team::ownerIdFor($execution->team_id),
                purpose: 'memory.compress',
            ));

            $compressed = trim($response->content);
        } catch (\Throwable $e) {
            Log::warning('CompressAndStoreExecutionMemoryJob: compression failed, using raw output', [
                'execution_id' => $this->executionId,
                'error' => $e->getMessage(),
            ]);
            $compressed = $rawContent;
        }

        if (empty($compressed)) {
            return;
        }

        // Semantic dedup: skip if a nearly identical memory already exists (threshold 0.92)
        try {
            $existing = $retrieveMemories->execute(
                agentId: $execution->agent_id,
                query: $compressed,
                topK: 1,
                threshold: 0.92,
                teamId: $execution->team_id,
            );

            if (! empty($existing)) {
                Log::debug('CompressAndStoreExecutionMemoryJob: skipping duplicate', [
                    'execution_id' => $this->executionId,
                ]);

                return;
            }
        } catch (\Throwable $e) {
            // pgvector unavailable in test env — proceed with store
        }

        $storeMemory->execute(
            teamId: $execution->team_id,
            agentId: $execution->agent_id,
            content: $compressed,
            sourceType: 'execution',
            sourceId: $execution->id,
            metadata: [
                'auto_captured' => true,
                'compressed' => true,
                'execution_id' => $execution->id,
            ],
        );
    }
}
