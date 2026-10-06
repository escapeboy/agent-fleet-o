<?php

namespace App\Domain\Chatbot\DTOs;

use App\Domain\Chatbot\Models\ChatbotAnswerCacheEntry;

/**
 * Outcome of one answer-cache lookup.
 *
 * decision: exact | hit | combine | reject | none (no candidate close enough) | error (judge failed)
 */
final readonly class AnswerCacheLookup
{
    /**
     * @param  list<ChatbotAnswerCacheEntry>  $entries  entries the served answer came from
     */
    public function __construct(
        public string $decision,
        public ?string $answer = null,
        public array $entries = [],
        public int $lookupMs = 0,
        public ?int $judgeMs = null,
        public int $judgeTokens = 0,
        public int $judgeCostCredits = 0,
    ) {}

    public function isServed(): bool
    {
        return $this->answer !== null && in_array($this->decision, ['exact', 'hit', 'combine'], true);
    }

    /** hit (exact or judged), combine, or miss — the status the metrics count. */
    public function status(): string
    {
        return match ($this->decision) {
            'exact', 'hit' => 'hit',
            'combine' => 'combine',
            default => 'miss',
        };
    }

    /**
     * Union of the cited sources of every entry the answer came from.
     *
     * @return list<array<string, mixed>>
     */
    public function sources(): array
    {
        $sources = [];
        foreach ($this->entries as $entry) {
            foreach ($entry->sources ?? [] as $source) {
                $sources[$source['chunk_id'] ?? json_encode($source)] = $source;
            }
        }

        return array_values($sources);
    }

    public function confidence(): ?float
    {
        $values = array_filter(array_map(fn ($e) => $e->confidence, $this->entries), fn ($v) => $v !== null);

        return $values === [] ? null : (float) min($values);
    }

    /**
     * What serving this answer saved versus a fresh generation, net of the judge call.
     *
     * @return array{tokens: int|null, cost_credits: int|null}
     */
    public function savings(): array
    {
        $avg = function (string $field): ?int {
            $values = array_filter(array_map(fn ($e) => $e->{$field}, $this->entries), fn ($v) => $v !== null);

            return $values === [] ? null : (int) round(array_sum($values) / count($values));
        };

        $tokens = $avg('generation_tokens');
        $credits = $avg('generation_cost_credits');

        return [
            'tokens' => $tokens === null ? null : max(0, $tokens - $this->judgeTokens),
            'cost_credits' => $credits === null ? null : max(0, $credits - $this->judgeCostCredits),
        ];
    }
}
