<?php

namespace App\Domain\Memory\Actions;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Memory\Enums\MemoryTier;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use Illuminate\Support\Facades\Log;

/**
 * Extracts a failure lesson from a failed Experiment using claude-haiku-4-5.
 *
 * Stores the lesson as a Memory record with tier = MemoryTier::Failures so it
 * receives the curated retrieval boost and surfaces prominently in future executions
 * for the same agent.
 */
class ExtractFailureLessonAction
{
    private const EXTRACT_MODEL = 'claude-haiku-4-5';

    private const SYSTEM_PROMPT = <<<'PROMPT'
You are a failure analyst. Analyze this failed experiment and extract a single, concise failure lesson.

The lesson must be:
- Actionable: describes what to do differently next time
- Durable: useful beyond this specific experiment
- Specific: names the root cause (e.g., tool timeout, missing context, ambiguous goal)

Return ONLY valid JSON (no markdown fences):
{
  "lesson": "concise failure lesson in one or two sentences",
  "root_cause": "brief root cause label",
  "confidence": 0.85,
  "tags": ["constraint"]
}

Confidence: 0.0 = speculative, 1.0 = clearly demonstrated.
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
                purpose: 'memory.failure_lesson',
                temperature: 0.1,
            ));

            $result = json_decode($response->content, true);

            if (! is_array($result) || empty($result['lesson'])) {
                Log::warning('ExtractFailureLessonAction: invalid response', [
                    'experiment_id' => $experimentId,
                    'content' => substr($response->content, 0, 200),
                ]);

                return;
            }

            $lesson = trim($result['lesson']);
            $confidence = (float) ($result['confidence'] ?? 0.5);
            $tags = array_values(array_filter((array) ($result['tags'] ?? []), 'is_string'));
            $rootCause = trim($result['root_cause'] ?? '');

            if ($confidence < 0.3) {
                return;
            }

            $this->storeMemory->execute(
                teamId: $teamId,
                agentId: $agentId,
                content: $lesson,
                sourceType: 'experiment',
                projectId: null,
                sourceId: $experimentId,
                metadata: [
                    'experiment_id' => $experimentId,
                    'experiment_title' => $experiment->title,
                    'final_status' => $experiment->status?->value,
                    'root_cause' => $rootCause,
                    'extracted_at' => now()->toIso8601String(),
                ],
                confidence: $confidence,
                tags: $tags,
                // Use Proposed tier so system-extracted lessons are retrievable but not
                // immediately given the curated (+0.10) boost. A separate curation step
                // can promote them to MemoryTier::Failures once validated.
                tier: MemoryTier::Proposed,
                proposedBy: 'system:failure_extractor',
            );

            Log::info('ExtractFailureLessonAction: lesson stored', [
                'experiment_id' => $experimentId,
                'agent_id' => $agentId,
                'root_cause' => $rootCause,
            ]);
        } catch (\Throwable $e) {
            Log::warning('ExtractFailureLessonAction: extraction failed, continuing', [
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

        $failedStages = $experiment->stages()
            ->whereNotNull('error')
            ->orderByDesc('created_at')
            ->limit(3)
            ->get(['stage', 'error', 'output_snapshot']);

        if ($failedStages->isNotEmpty()) {
            $parts[] = "\n## Failed Stages";
            foreach ($failedStages as $stage) {
                $parts[] = "Stage: {$stage->stage}";
                if (! empty($stage->error)) {
                    $errorText = is_array($stage->error)
                        ? json_encode($stage->error, JSON_UNESCAPED_UNICODE)
                        : (string) $stage->error;
                    $parts[] = '<stage_error>'.htmlspecialchars(substr($errorText, 0, 500), ENT_XML1).'</stage_error>';
                }
            }
        }

        return implode("\n", $parts);
    }
}
