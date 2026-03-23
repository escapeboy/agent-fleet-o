<?php

namespace App\Domain\Memory\Actions;

use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentExecution;
use App\Domain\Memory\Enums\MemoryTier;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Services\ProviderResolver;
use Illuminate\Support\Facades\Log;

/**
 * Extracts durable facts from a completed AgentExecution using claude-haiku-4-5.
 *
 * Facts are filtered by confidence >= 0.5 and stored as individual Memory records
 * via StoreMemoryAction, enriched with confidence scores and semantic tags.
 */
class ExtractAndStoreMemoriesAction
{
    private const EXTRACT_MODEL = 'claude-haiku-4-5';

    private const MIN_CONFIDENCE = 0.5;

    private const SYSTEM_PROMPT = <<<'PROMPT'
You are a memory extractor. Analyze this agent execution and extract durable, reusable facts.

Extract ONLY facts that will remain useful across future executions:
- Capabilities: what this agent does well
- Constraints: limitations or boundaries observed
- Preferences: output format, tone, or style patterns
- Patterns: recurring behaviors or approaches
- Domain knowledge: specific knowledge demonstrated
- Tooling: which tools or APIs were used effectively

DO NOT extract task-specific details (e.g., "processed order #123").

Return ONLY valid JSON (no markdown fences):
{
  "facts": [
    {
      "fact": "concise, durable statement",
      "confidence": 0.85,
      "tags": ["capability"]
    }
  ]
}

Confidence: 0.0 = speculative, 1.0 = clearly demonstrated.
Tags must be one or more of: capability, constraint, preference, pattern, domain, tooling
PROMPT;

    public function __construct(
        private readonly AiGatewayInterface $gateway,
        private readonly ProviderResolver $providerResolver,
        private readonly StoreMemoryAction $storeMemory,
    ) {}

    public function execute(string $agentId, string $teamId, string $executionId): void
    {
        if (! config('memory.enabled', true)) {
            return;
        }

        $execution = AgentExecution::withoutGlobalScopes()
            ->where('id', $executionId)
            ->where('agent_id', $agentId)
            ->where('status', 'completed')
            ->first();

        if (! $execution) {
            return;
        }

        $agent = Agent::withoutGlobalScopes()->find($agentId);
        if (! $agent) {
            return;
        }

        try {
            $team = Team::find($teamId);
            $resolved = $this->providerResolver->resolve(agent: $agent, team: $team);

            $prompt = $this->buildExtractionPrompt($execution);

            $response = $this->gateway->complete(new AiRequestDTO(
                provider: $resolved['provider'],
                model: self::EXTRACT_MODEL,
                systemPrompt: self::SYSTEM_PROMPT,
                userPrompt: $prompt,
                maxTokens: 512,
                teamId: $teamId,
                agentId: $agentId,
                experimentId: $execution->experiment_id,
                purpose: 'memory.extract',
                temperature: 0.1,
            ));

            $result = json_decode($response->content, true);

            if (! is_array($result) || ! isset($result['facts']) || ! is_array($result['facts'])) {
                Log::warning('ExtractAndStoreMemoriesAction: invalid response format', [
                    'agent_id' => $agentId,
                    'execution_id' => $executionId,
                    'content' => substr($response->content, 0, 200),
                ]);

                return;
            }

            $stored = 0;
            foreach ($result['facts'] as $item) {
                $fact = trim($item['fact'] ?? '');
                $confidence = (float) ($item['confidence'] ?? 0.0);
                $tags = array_values(array_filter((array) ($item['tags'] ?? []), 'is_string'));

                if ($fact === '' || $confidence < self::MIN_CONFIDENCE) {
                    continue;
                }

                $this->storeMemory->execute(
                    teamId: $teamId,
                    agentId: $agentId,
                    content: $fact,
                    sourceType: 'execution',
                    projectId: null,
                    sourceId: $executionId,
                    metadata: ['extracted_at' => now()->toIso8601String()],
                    confidence: $confidence,
                    tags: $tags,
                    tier: MemoryTier::Proposed,
                    proposedBy: "agent:{$agentId}",
                );

                $stored++;
            }

            Log::info('ExtractAndStoreMemoriesAction: extraction complete', [
                'agent_id' => $agentId,
                'execution_id' => $executionId,
                'facts_extracted' => count($result['facts']),
                'facts_stored' => $stored,
            ]);
        } catch (\Throwable $e) {
            Log::warning('ExtractAndStoreMemoriesAction: extraction failed, continuing', [
                'agent_id' => $agentId,
                'execution_id' => $executionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function buildExtractionPrompt(AgentExecution $execution): string
    {
        $parts = [];

        if (! empty($execution->input)) {
            $parts[] = '## Input'."\n".json_encode($execution->input, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }

        if (! empty($execution->output)) {
            $outputText = json_encode($execution->output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            // Limit output to 3000 chars to avoid excessive token use
            if (strlen($outputText) > 3000) {
                $outputText = substr($outputText, 0, 3000).'... [truncated]';
            }
            $parts[] = '## Output'."\n".$outputText;
        }

        if (! empty($execution->tools_used)) {
            $parts[] = '## Tools Used'."\n".json_encode($execution->tools_used, JSON_UNESCAPED_UNICODE);
        }

        return implode("\n\n", $parts);
    }
}
