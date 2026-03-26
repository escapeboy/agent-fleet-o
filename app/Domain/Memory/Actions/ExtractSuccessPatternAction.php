<?php

namespace App\Domain\Memory\Actions;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Memory\Enums\MemoryCategory;
use App\Domain\Memory\Enums\MemoryTier;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use Illuminate\Support\Facades\Log;

/**
 * Extracts a reusable success pattern from a completed Experiment using claude-haiku-4-5.
 *
 * Stores the pattern as a Memory record with tier = MemoryTier::Successes so it
 * receives the curated retrieval boost and surfaces prominently in future executions
 * for the same agent. Symmetric counterpart to ExtractFailureLessonAction.
 *
 * Inspired by PentaGI's "successful tools search" memory strategy.
 */
class ExtractSuccessPatternAction
{
    private const EXTRACT_MODEL = 'claude-haiku-4-5';

    private const SYSTEM_PROMPT = <<<'PROMPT'
You are a success analyst. Analyze this completed experiment and extract a reusable success pattern.

The pattern must be:
- Actionable: describes what to do in similar situations
- Transferable: useful beyond this specific experiment
- Specific: names the key technique (e.g., tool name, approach, sequence used)

Return ONLY valid JSON (no markdown fences):
{
  "pattern": "concise success pattern in one or two sentences",
  "key_technique": "brief technique label",
  "confidence": 0.85,
  "tags": ["tooling"]
}

Confidence: 0.0 = marginal success, 1.0 = clearly effective.
Tags must be one or more of: capability, constraint, preference, pattern, domain, tooling
PROMPT;

    public function __construct(
        private readonly AiGatewayInterface $gateway,
        private readonly StoreMemoryAction $storeMemory,
    ) {}

    public function execute(string $experimentId, string $teamId): void
    {
        if (! config('memory.enabled', true)) {
            return;
        }

        $experiment = Experiment::withoutGlobalScopes()
            ->where('id', $experimentId)
            ->where('team_id', $teamId)
            ->first();

        if (! $experiment) {
            return;
        }

        $agentId = $experiment->agent_id;
        if (! $agentId) {
            return;
        }

        try {
            $provider = config('llm_pricing.default_provider', 'anthropic');

            $response = $this->gateway->complete(new AiRequestDTO(
                provider: $provider,
                model: self::EXTRACT_MODEL,
                systemPrompt: self::SYSTEM_PROMPT,
                userPrompt: $this->buildPrompt($experiment),
                maxTokens: 256,
                teamId: $teamId,
                experimentId: $experimentId,
                purpose: 'memory.success_pattern',
                temperature: 0.1,
            ));

            $result = json_decode($response->content, true);

            if (! is_array($result) || empty($result['pattern'])) {
                Log::warning('ExtractSuccessPatternAction: invalid response', [
                    'experiment_id' => $experimentId,
                    'content' => substr($response->content, 0, 200),
                ]);

                return;
            }

            $pattern = trim($result['pattern']);
            $confidence = (float) ($result['confidence'] ?? 0.5);
            $tags = array_values(array_filter((array) ($result['tags'] ?? []), 'is_string'));
            $keyTechnique = trim($result['key_technique'] ?? '');

            if ($confidence < 0.3) {
                return;
            }

            $this->storeMemory->execute(
                teamId: $teamId,
                agentId: $agentId,
                content: $pattern,
                sourceType: 'experiment',
                projectId: null,
                sourceId: $experimentId,
                metadata: [
                    'experiment_id' => $experimentId,
                    'experiment_title' => $experiment->title,
                    'final_status' => $experiment->status?->value,
                    'key_technique' => $keyTechnique,
                    'extracted_at' => now()->toIso8601String(),
                ],
                confidence: $confidence,
                tags: $tags,
                tier: MemoryTier::Successes,
                category: MemoryCategory::Behavior,
                proposedBy: 'system:success_extractor',
            );

            Log::info('ExtractSuccessPatternAction: pattern stored', [
                'experiment_id' => $experimentId,
                'agent_id' => $agentId,
                'key_technique' => $keyTechnique,
            ]);
        } catch (\Throwable $e) {
            Log::warning('ExtractSuccessPatternAction: extraction failed, continuing', [
                'experiment_id' => $experimentId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function buildPrompt(Experiment $experiment): string
    {
        // User-controlled fields are wrapped in XML-style delimiters to prevent prompt injection.
        $parts = [
            '## Experiment',
            '<experiment_title>'.htmlspecialchars((string) $experiment->title, ENT_XML1).'</experiment_title>',
        ];

        if (! empty($experiment->thesis)) {
            $parts[] = '<experiment_thesis>'.htmlspecialchars((string) $experiment->thesis, ENT_XML1).'</experiment_thesis>';
        }

        $parts[] = 'Final status: '.($experiment->status?->value ?? 'unknown');

        $completedStages = $experiment->stages()
            ->whereNull('error')
            ->orderByDesc('created_at')
            ->limit(3)
            ->get(['stage', 'output_snapshot']);

        if ($completedStages->isNotEmpty()) {
            $parts[] = "\n## Completed Stages";
            foreach ($completedStages as $stage) {
                $parts[] = "Stage: {$stage->stage}";
                if (! empty($stage->output_snapshot)) {
                    $outputText = is_array($stage->output_snapshot)
                        ? json_encode($stage->output_snapshot, JSON_UNESCAPED_UNICODE)
                        : (string) $stage->output_snapshot;
                    // Truncate after encoding to avoid splitting mid-entity.
                    $parts[] = '<stage_output>'.substr(htmlspecialchars($outputText, ENT_XML1), 0, 400).'</stage_output>';
                }
            }
        }

        return implode("\n", $parts);
    }
}
