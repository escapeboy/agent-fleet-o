<?php

namespace App\Domain\Signal\Services;

use App\Domain\Signal\Enums\SignalStatus;
use App\Domain\Signal\Models\Signal;
use App\Infrastructure\AI\Services\EmbeddingService;
use Illuminate\Support\Facades\DB;

/**
 * Learned, team-personalised signal relevance.
 *
 * Builds a preference profile from the team's own outcomes — signals it
 * Resolved are "liked", signals it Dismissed are "disliked" — and scores new
 * signals by embedding similarity to (liked centroid − disliked centroid),
 * blended additively with the Phase-2 novelty score and the stateless LLM
 * quality score. Every contribution is reported so the ranking is explainable.
 *
 * Distinct from (and complementary to) the stateless ScoreSignalRelevanceJob.
 */
class SignalRelevanceScorer
{
    /** Minimum liked AND disliked examples before learning activates (cf. CondenseIt's min_ratings_for_learning). */
    private const MIN_LABELED = 5;

    /** How many recent labelled signals to average into each centroid. */
    private const SAMPLE = 50;

    private const W_SIMILARITY = 0.6;

    private const W_NOVELTY = 0.25;

    private const W_LLM = 0.15;

    public function __construct(
        private readonly EmbeddingService $embeddingService,
    ) {}

    /**
     * Score a signal and persist the learned relevance + breakdown.
     *
     * @return array{score: float, breakdown: array<int, array<string, mixed>>}|null
     *                                                                               Null when scoring is not possible (no pgvector, empty text,
     *                                                                               embedding failure, or insufficient labelled history).
     */
    public function score(Signal $signal): ?array
    {
        if (! $this->pgvectorAvailable()) {
            return null;
        }

        $text = $this->textFor($signal);
        if (mb_strlen($text) < 20) {
            return null;
        }

        $vector = $this->embeddingService->embedForTeam($text, $signal->team_id);
        if (empty($vector)) {
            return null;
        }

        // Persist the candidate's embedding regardless of whether we can score
        // yet — this bootstraps the corpus so early signals can later seed centroids.
        $this->storeEmbedding($signal, $vector);

        $liked = $this->centroid($signal->team_id, SignalStatus::Resolved);
        $disliked = $this->centroid($signal->team_id, SignalStatus::Dismissed);
        if ($liked === null || $disliked === null) {
            return null;
        }

        $result = $this->combine(
            simLiked: $this->cosine($vector, $liked),
            simDisliked: $this->cosine($vector, $disliked),
            novelty: isset($signal->metadata['novelty']) ? (int) $signal->metadata['novelty'] : null,
            llmScore: $signal->relevance_score,
        );

        $signal->learned_relevance_score = $result['score'];
        $signal->learned_relevance_at = now();
        $metadata = $signal->metadata ?? [];
        $metadata['learned_relevance'] = $result['breakdown'];
        $signal->metadata = $metadata;
        $signal->save();

        return $result;
    }

    /**
     * Pure additive blend — unit-testable without a database or embeddings.
     *
     * @return array{score: float, breakdown: array<int, array{signal: string, weight: float, value: float, contribution: float}>}
     */
    public function combine(float $simLiked, float $simDisliked, ?int $novelty, ?float $llmScore): array
    {
        // How much closer to "liked" than "disliked", mapped from [-1,1] to [0,1].
        $preference = $this->clamp(($simLiked - $simDisliked + 1.0) / 2.0);
        $noveltyValue = $novelty !== null ? $this->clamp(($novelty - 1) / 4.0) : 0.0;
        $llmValue = $llmScore !== null ? $this->clamp($llmScore) : 0.0;

        $breakdown = [
            $this->row('preference_similarity', self::W_SIMILARITY, $preference),
            $this->row('novelty', self::W_NOVELTY, $noveltyValue),
            $this->row('llm_quality', self::W_LLM, $llmValue),
        ];

        $score = array_sum(array_column($breakdown, 'contribution'));

        return ['score' => round($this->clamp($score), 4), 'breakdown' => $breakdown];
    }

    public function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        $n = min(count($a), count($b));

        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }

    /**
     * @return array{signal: string, weight: float, value: float, contribution: float}
     */
    private function row(string $name, float $weight, float $value): array
    {
        return [
            'signal' => $name,
            'weight' => $weight,
            'value' => round($value, 4),
            'contribution' => round($weight * $value, 4),
        ];
    }

    private function clamp(float $v): float
    {
        return max(0.0, min(1.0, $v));
    }

    private function textFor(Signal $signal): string
    {
        $payload = $signal->payload ?? [];

        return trim(implode("\n\n", array_filter([
            $payload['title'] ?? $payload['subject'] ?? $payload['summary'] ?? '',
            $payload['description'] ?? $payload['body'] ?? $payload['content'] ?? $payload['text'] ?? '',
        ])));
    }

    /**
     * Average the embeddings of the team's most recent signals in the given
     * terminal status. Returns null until MIN_LABELED examples exist.
     *
     * @return float[]|null
     */
    private function centroid(?string $teamId, SignalStatus $status): ?array
    {
        $rows = Signal::query()
            ->withoutGlobalScopes()
            ->when($teamId, fn ($q) => $q->where('team_id', $teamId))
            ->where('status', $status->value)
            ->whereNotNull('embedding')
            ->orderByDesc('received_at')
            ->limit(self::SAMPLE)
            ->pluck('embedding');

        if ($rows->count() < self::MIN_LABELED) {
            return null;
        }

        $sum = null;
        $count = 0;

        foreach ($rows as $raw) {
            $vec = $this->parseVector((string) $raw);
            if ($vec === []) {
                continue;
            }
            if ($sum === null) {
                $sum = array_fill(0, count($vec), 0.0);
            }
            foreach ($vec as $i => $v) {
                $sum[$i] += $v;
            }
            $count++;
        }

        if ($sum === null || $count === 0) {
            return null;
        }

        return array_map(fn ($s) => $s / $count, $sum);
    }

    private function storeEmbedding(Signal $signal, array $vector): void
    {
        DB::statement(
            'UPDATE signals SET embedding = ?::vector WHERE id = ?',
            [$this->embeddingService->formatForPgvector($vector), $signal->id],
        );
    }

    /**
     * @return float[]
     */
    private function parseVector(string $raw): array
    {
        // pgvector renders as a JSON-style array literal, e.g. "[0.1,0.2,...]".
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_map('floatval', $decoded) : [];
    }

    private function pgvectorAvailable(): bool
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return false;
        }

        try {
            return (bool) DB::selectOne("SELECT 1 FROM pg_extension WHERE extname = 'vector'");
        } catch (\Throwable) {
            return false;
        }
    }
}
