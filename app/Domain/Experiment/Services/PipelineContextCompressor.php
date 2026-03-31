<?php

namespace App\Domain\Experiment\Services;

use App\Domain\Experiment\Enums\StageStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PipelineContextCompressor
{
    private const CHARS_PER_TOKEN = 4;

    public function shouldCompress(Experiment $experiment, ExperimentStage $currentStage): bool
    {
        if (! config('experiments.context_compression.enabled', true)) {
            return false;
        }

        $threshold = config('experiments.context_compression.threshold_tokens', 30000);
        $precedingContext = $this->getPrecedingContextLength($experiment, $currentStage);
        $estimatedTokens = $precedingContext / self::CHARS_PER_TOKEN;

        return $estimatedTokens > $threshold;
    }

    public function compress(Experiment $experiment, ExperimentStage $currentStage): string
    {
        $headCount = config('experiments.context_compression.head_stages', 1);
        $tailCount = config('experiments.context_compression.tail_stages', 2);

        $stages = $experiment->stages()
            ->where('status', StageStatus::Completed->value)
            ->where('started_at', '<', $currentStage->started_at ?? now())
            ->orderBy('started_at')
            ->get();

        if ($stages->count() <= $headCount + $tailCount) {
            return $this->serializeStages($stages);
        }

        $head = $stages->take($headCount);
        $tail = $stages->slice(-$tailCount);
        $middle = $stages->slice($headCount, $stages->count() - $headCount - $tailCount);

        // Prune middle stage outputs (cheap, no LLM call)
        $prunedMiddle = $middle->map(fn ($s) => [
            'stage' => $s->stage instanceof \BackedEnum ? $s->stage->value : $s->stage,
            'status' => $s->status instanceof \BackedEnum ? $s->status->value : $s->status,
            'output_summary' => Str::limit(
                is_array($s->output_snapshot) ? json_encode($s->output_snapshot) : (string) $s->output_snapshot,
                500,
                '...',
            ),
        ]);

        $middleText = json_encode($prunedMiddle->toArray());

        // LLM-summarize if pruned middle still too large (cached to avoid redundant calls)
        if (mb_strlen($middleText) / self::CHARS_PER_TOKEN > 10_000) {
            $cacheKey = "ctx_compress:{$experiment->id}:{$middle->count()}";
            $middleText = Cache::remember($cacheKey, 3600, function () use ($experiment, $prunedMiddle, $middleText) {
                return $this->llmSummarize($experiment, $prunedMiddle) ?? $middleText;
            });
        }

        $originalTokens = (int) ($this->getPrecedingContextLength($experiment, $currentStage) / self::CHARS_PER_TOKEN);
        $compressedTokens = (int) (mb_strlen($middleText) / self::CHARS_PER_TOKEN)
            + (int) (mb_strlen($this->serializeStages($head)) / self::CHARS_PER_TOKEN)
            + (int) (mb_strlen($this->serializeStages($tail)) / self::CHARS_PER_TOKEN);

        Log::info('PipelineContextCompressor: Compressed context', [
            'experiment_id' => $experiment->id,
            'original_tokens' => $originalTokens,
            'compressed_tokens' => $compressedTokens,
            'ratio' => $originalTokens > 0 ? round($compressedTokens / $originalTokens, 2) : 0,
            'stages_compressed' => $middle->count(),
        ]);

        return implode("\n\n", [
            "## Experiment: {$experiment->title}",
            "### Goal\n{$experiment->thesis}",
            '### Completed Stages (compressed)',
            "**First stage (full):**\n".$this->serializeStages($head),
            "**Middle stages (summary — {$middle->count()} stages compressed):**\n{$middleText}",
            "**Recent stages (full):**\n".$this->serializeStages($tail),
        ]);
    }

    private function llmSummarize(Experiment $experiment, Collection $stages): ?string
    {
        try {
            $response = app(AiGatewayInterface::class)->execute(new AiRequestDTO(
                provider: 'anthropic',
                model: 'claude-haiku-4-5',
                systemPrompt: 'Summarize these experiment stages into a structured progress report. Preserve: key decisions made, outputs produced, errors encountered. Drop: raw data, verbose tool results, repeated information.',
                userMessage: json_encode($stages->toArray()),
                teamId: $experiment->team_id,
                purpose: 'context_compression',
                maxTokens: 2048,
            ));

            return $response->content;
        } catch (\Throwable $e) {
            Log::warning('PipelineContextCompressor: LLM summarization failed, using pruned output', [
                'experiment_id' => $experiment->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function serializeStages(Collection $stages): string
    {
        return $stages->map(fn ($s) => '**['.
            ($s->stage instanceof \BackedEnum ? $s->stage->value : $s->stage).
            ']** (status: '.($s->status instanceof \BackedEnum ? $s->status->value : $s->status).")\n".
            json_encode($s->output_snapshot),
        )->implode("\n\n");
    }

    private function getPrecedingContextLength(Experiment $experiment, ExperimentStage $currentStage): int
    {
        return $experiment->stages()
            ->where('status', StageStatus::Completed->value)
            ->where('started_at', '<', $currentStage->started_at ?? now())
            ->get()
            ->sum(fn ($s) => mb_strlen(
                is_array($s->output_snapshot) ? json_encode($s->output_snapshot) : (string) $s->output_snapshot,
            ));
    }
}
