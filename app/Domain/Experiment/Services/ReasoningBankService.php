<?php

namespace App\Domain\Experiment\Services;

use App\Domain\Experiment\Enums\StageType;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ReasoningBankEntry;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Services\EmbeddingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ReasoningBankService
{
    public function __construct(
        private readonly AiGatewayInterface $gateway,
        private readonly EmbeddingService $embedder,
    ) {}

    /**
     * Record a completed experiment into the reasoning bank.
     * Skips silently if embedding generation fails.
     */
    public function record(Experiment $experiment): void
    {
        try {
            $goalText = $experiment->thesis ?? $experiment->title;
            $toolSequence = $this->extractToolSequence($experiment);
            $outcomeSummary = $this->generateOutcomeSummary($experiment);

            $embedding = $this->embedder->embed($goalText);

            ReasoningBankEntry::create([
                'team_id' => $experiment->team_id,
                'experiment_id' => $experiment->id,
                'goal_text' => $goalText,
                'tool_sequence' => $toolSequence,
                'outcome_summary' => $outcomeSummary,
                'embedding' => $embedding,
            ]);
        } catch (\Throwable $e) {
            Log::warning('ReasoningBank: failed to record entry', [
                'experiment_id' => $experiment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Fetch top-K similar past strategies for a given goal text.
     *
     * @return Collection<int, ReasoningBankEntry>
     */
    public function fetchHints(string $goalText, string $teamId, int $k = 3): Collection
    {
        $embedding = $this->embedder->embed($goalText);
        $pgvectorStr = $this->embedder->formatForPgvector($embedding);

        return ReasoningBankEntry::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->orderByRaw('embedding <=> ?::vector', [$pgvectorStr])
            ->limit($k)
            ->get();
    }

    private function extractToolSequence(Experiment $experiment): array
    {
        $buildingStage = $experiment->stages()
            ->where('stage', StageType::Building->value)
            ->whereNotNull('output_snapshot')
            ->latest('created_at')
            ->first();

        if (! $buildingStage) {
            return [];
        }

        $snapshot = $buildingStage->output_snapshot;

        return $snapshot['tools_used'] ?? [];
    }

    private function generateOutcomeSummary(Experiment $experiment): string
    {
        $evaluationStage = $experiment->stages()
            ->where('stage', StageType::Evaluating->value)
            ->whereNotNull('output_snapshot')
            ->latest('created_at')
            ->first();

        $evalOutput = $evaluationStage
            ? json_encode($evaluationStage->output_snapshot, JSON_UNESCAPED_UNICODE)
            : 'No evaluation output available.';

        $llm = $this->resolveLlm($experiment);

        $request = new AiRequestDTO(
            provider: $llm['provider'],
            model: $llm['model'],
            systemPrompt: 'Summarize the outcome of this experiment in exactly 2 sentences. Be specific and factual. Return plain text only.',
            userPrompt: "Experiment: {$experiment->title}\nThesis: {$experiment->thesis}\n\nEvaluation output:\n{$evalOutput}",
            maxTokens: 256,
            teamId: $experiment->team_id,
            experimentId: $experiment->id,
            purpose: 'reasoning_bank_summary',
            temperature: 0.3,
        );

        $response = $this->gateway->complete($request);

        return trim($response->content);
    }

    private function resolveLlm(Experiment $experiment): array
    {
        $config = $experiment->constraints ?? [];

        if (! empty($config['llm_provider']) && ! empty($config['llm_model'])) {
            return ['provider' => $config['llm_provider'], 'model' => $config['llm_model']];
        }

        return [
            'provider' => config('llm_pricing.default_provider', 'anthropic'),
            'model' => config('llm_pricing.default_model', 'claude-sonnet-4-5'),
        ];
    }
}
