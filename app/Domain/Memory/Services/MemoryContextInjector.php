<?php

namespace App\Domain\Memory\Services;

use App\Domain\Memory\Actions\RetrieveRelevantMemoriesAction;
use App\Domain\Memory\Actions\UnifiedMemorySearchAction;
use App\Domain\Memory\Enums\MemoryTier;
use App\Domain\Memory\Models\Memory;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class MemoryContextInjector
{
    public function __construct(
        private readonly RetrieveRelevantMemoriesAction $retrieveMemories,
        private readonly ?UnifiedMemorySearchAction $unifiedSearch = null,
        private readonly ?MemoryRelevanceJudge $judge = null,
    ) {}

    /**
     * Build a memory context string for injection into agent system prompts.
     *
     * When unified search is enabled, queries vector memory, knowledge graph,
     * and keyword search via RRF fusion. Each result includes source attribution.
     *
     * Always appends the top 3 past failure lessons (tier = failures) for the
     * team so agents avoid repeating known failure patterns.
     *
     * Returns null if memory is disabled, input is empty, or no relevant memories found.
     */
    public function buildContext(
        ?string $agentId,
        mixed $input,
        ?string $projectId = null,
        ?string $teamId = null,
        ?string $userId = null,
    ): ?string {
        if (! config('memory.enabled', true) || empty($input)) {
            return null;
        }

        $queryText = is_string($input) ? $input : json_encode($input);

        // Try unified search first (RRF fusion across vector + KG + keyword)
        if ($this->unifiedSearch && config('memory.unified_search.enabled', true) && $teamId) {
            $context = $this->buildUnifiedContext($teamId, $queryText, $agentId, $projectId, $userId);
        } else {
            // Fallback to vector-only search
            $context = $this->buildVectorOnlyContext($agentId, $queryText, $projectId, $teamId, $userId);
        }

        // Append failure lessons for the team so agents avoid known failure patterns.
        $failureLessons = $this->buildFailureLessonsContext($teamId);
        if ($failureLessons !== null) {
            $context = $context !== null
                ? $context."\n\n".$failureLessons
                : $failureLessons;
        }

        return $context;
    }

    /**
     * Optional tier-2 deep judgment: re-score retrieved items for task relevance
     * and drop sub-threshold ones before formatting. Off by default; only runs
     * when the candidate set is large enough to be worth a cheap LLM call.
     * Fails open (returns the input unchanged) when disabled, unavailable, or
     * lacking an acting user.
     *
     * @template T
     *
     * @param  Collection<int, T>  $items
     * @param  callable(T): string  $contentOf
     * @return Collection<int, T>
     */
    private function applyDeepJudgment(
        Collection $items,
        callable $contentOf,
        string $query,
        ?string $teamId,
        ?string $userId,
    ): Collection {
        if ($this->judge === null
            || ! config('memory.deep_judgment.enabled', false)
            || ! $teamId
            || ! $userId) {
            return $items;
        }

        $minCandidates = (int) config('memory.deep_judgment.min_candidates', 4);
        if ($items->count() < $minCandidates) {
            return $items;
        }

        // Reference each item by positional index so the judge works uniformly
        // across memory rows and KG facts (which lack a shared id shape).
        $candidates = $items->values()
            ->map(fn ($item, $i) => ['id' => (string) $i, 'content' => $contentOf($item)])
            ->all();

        $keptIds = array_flip($this->judge->judge($query, $candidates, $teamId, $userId));

        return $items->values()
            ->filter(fn ($item, $i) => isset($keptIds[(string) $i]))
            ->values();
    }

    /**
     * Build a "Past Failure Lessons" section from the top 3 failure-tier memories for the team.
     *
     * These lessons are sourced from experiments that previously failed. Surfacing them
     * in the system prompt helps agents avoid repeating the same mistakes.
     */
    private function buildFailureLessonsContext(?string $teamId): ?string
    {
        if (! $teamId) {
            return null;
        }

        try {
            $lessons = Memory::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->where('tier', MemoryTier::Failures)
                ->where(fn ($q) => $q->whereNull('proposal_status')->orWhere('proposal_status', '!=', 'rejected'))
                ->orderByDesc('importance')
                ->orderByDesc('created_at')
                ->limit(3)
                ->get(['content', 'source_id', 'created_at']);
        } catch (\Throwable) {
            return null;
        }

        if ($lessons->isEmpty()) {
            return null;
        }

        $lines = $lessons->map(function (Memory $m): string {
            $date = $m->created_at?->format('Y-m-d') ?? 'unknown';
            $expId = $m->source_id ? substr($m->source_id, 0, 8) : 'unknown';

            return "[failure: {$date} | experiment: {$expId}] {$m->content}";
        })->implode("\n");

        return "## Past Failure Lessons\n{$lines}";
    }

    /**
     * Build context using unified RRF search with source attribution.
     */
    private function buildUnifiedContext(
        string $teamId,
        string $query,
        ?string $agentId,
        ?string $projectId,
        ?string $userId = null,
    ): ?string {
        $results = $this->unifiedSearch->execute(
            teamId: $teamId,
            query: $query,
            agentId: $agentId,
            projectId: $projectId,
        );

        if ($results->isEmpty()) {
            return null;
        }

        $results = $this->applyDeepJudgment(
            $results,
            fn ($item) => (string) ($item['content'] ?? ''),
            $query,
            $teamId,
            $userId,
        );

        $lines = $results->map(fn ($item, $i) => $this->formatResultWithAttribution($item, $i + 1))->implode("\n\n");

        return $this->contextHeader()."\n\n{$lines}";
    }

    /**
     * Format a single search result with provenance metadata.
     */
    private function formatResultWithAttribution(array $item, int $rank): string
    {
        $meta = $item['metadata'] ?? [];
        $type = $item['type'];

        if ($type === 'kg_fact') {
            $source = $meta['source_entity'] ?? 'Unknown';
            $target = $meta['target_entity'] ?? 'Unknown';
            $relation = $meta['relation_type'] ?? '';
            $since = isset($meta['valid_at']) ? ' | since: '.date('M Y', strtotime($meta['valid_at'])) : '';

            return "{$rank}. [source: kg_fact | entity: {$source} → {$target} | relation: {$relation}{$since}]\n   {$item['content']}";
        }

        // Memory type
        $sourceType = $meta['source_type'] ?? 'unknown';
        $importance = isset($meta['importance']) ? (int) round($meta['importance'] * 10) : 5;
        $retrievals = $meta['retrieval_count'] ?? 0;
        $age = isset($meta['created_at']) ? $this->humanAge($meta['created_at']) : 'unknown';

        $parts = ["source: {$sourceType}"];
        $parts[] = "age: {$age}";
        $parts[] = "importance: {$importance}/10";

        if ($retrievals > 0) {
            $parts[] = "used: {$retrievals}x";
        }

        if ($sourceType === 'consolidated' && isset($meta['metadata']['source_ids'])) {
            $count = count($meta['metadata']['source_ids']);
            $parts[] = "based_on: {$count} observations";
        }

        $attribution = implode(' | ', $parts);

        $cite = $this->citationToken($item['id'] ?? ($meta['id'] ?? null));
        $passage = $this->passageText((string) ($item['content'] ?? ''), $item['chunk_context'] ?? ($meta['chunk_context'] ?? null));
        $line = "{$rank}. {$cite}[{$attribution}]\n   {$passage}";

        $vetoes = $this->formatRejectedAlternatives($meta['rejected_alternatives'] ?? []);
        if ($vetoes !== null) {
            $line .= "\n   Ruled out: {$vetoes}";
        }

        return $line;
    }

    /**
     * Render structured rejected alternatives as a single inline veto string,
     * so an agent sees "don't do that again" before re-proposing a ruled-out
     * option. Returns null when there is nothing to show.
     *
     * @param  mixed  $rejected  Expected: array<int, array{option?: string, reason?: string}>
     */
    private function formatRejectedAlternatives(mixed $rejected): ?string
    {
        if (! is_array($rejected) || $rejected === []) {
            return null;
        }

        $parts = [];
        foreach ($rejected as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $option = trim((string) ($entry['option'] ?? ''));
            if ($option === '') {
                continue;
            }
            $reason = trim((string) ($entry['reason'] ?? ''));
            $parts[] = $reason !== '' ? "✗ {$option} — {$reason}" : "✗ {$option}";
        }

        return $parts === [] ? null : implode('; ', $parts);
    }

    /**
     * Build context using vector-only search (legacy fallback).
     */
    private function buildVectorOnlyContext(
        ?string $agentId,
        string $query,
        ?string $projectId,
        ?string $teamId,
        ?string $userId = null,
    ): ?string {
        $scope = $projectId ? 'project' : 'agent';

        $memories = $this->retrieveMemories->execute(
            agentId: $agentId,
            query: $query,
            projectId: $projectId,
            scope: $scope,
            teamId: $teamId,
        );

        if ($memories->isEmpty()) {
            return null;
        }

        $memories = $this->applyDeepJudgment(
            $memories,
            fn ($m) => (string) $m->content,
            $query,
            $teamId,
            $userId,
        );

        $lines = $memories->map(function ($m, $i) {
            $sourceType = $m->source_type ?? 'unknown';
            $importance = (int) round($m->effective_importance * 10);
            $age = $m->created_at?->diffForHumans() ?? 'unknown';
            $retrievals = $m->retrieval_count ?? 0;

            $parts = ["source: {$sourceType}", "age: {$age}", "importance: {$importance}/10"];
            if ($retrievals > 0) {
                $parts[] = "used: {$retrievals}x";
            }

            $attribution = implode(' | ', $parts);
            $rank = $i + 1;

            $cite = $this->citationToken($m->id ?? null);
            $passage = $this->passageText((string) $m->content, $m->chunk_context ?? null);
            $line = "{$rank}. {$cite}[{$attribution}]\n   {$passage}";

            $vetoes = $this->formatRejectedAlternatives($m->rejected_alternatives ?? []);
            if ($vetoes !== null) {
                $line .= "\n   Ruled out: {$vetoes}";
            }

            return $line;
        })->implode("\n\n");

        return $this->contextHeader()."\n\n{$lines}";
    }

    /**
     * Evidence objects (Web IQ borrow): when enabled, injected context items
     * carry a stable citation token and prefer passage-level chunk_context over
     * the full content — optimising information density per token while keeping
     * outputs attributable. Off by default → byte-for-byte legacy formatting.
     */
    private function evidenceCitationsEnabled(): bool
    {
        return (bool) config('memory.evidence_citations.enabled', false);
    }

    /**
     * The context block header. Adds a citation instruction when evidence
     * citations are enabled.
     */
    private function contextHeader(): string
    {
        $header = "## Relevant Context\nEach item includes its source and reliability indicators.";

        if ($this->evidenceCitationsEnabled()) {
            $header .= "\nCite any fact you use with the [[mem:id]] token shown before each item.";
        }

        return $header;
    }

    /**
     * A stable per-item citation token (e.g. "[[mem:019e8cb3]] ") when evidence
     * citations are enabled and an id is available; otherwise an empty string.
     */
    private function citationToken(?string $id): string
    {
        if (! $this->evidenceCitationsEnabled() || $id === null || $id === '') {
            return '';
        }

        return '[[mem:'.substr($id, 0, 8).']] ';
    }

    /**
     * Prefer the passage-level chunk_context (an evidence object) over the full
     * content when citations are enabled and a chunk_context is present.
     */
    private function passageText(string $content, ?string $chunkContext): string
    {
        if ($this->evidenceCitationsEnabled() && is_string($chunkContext) && trim($chunkContext) !== '') {
            return $chunkContext;
        }

        return $content;
    }

    /**
     * Convert an ISO timestamp to a human-readable age string.
     */
    private function humanAge(string $isoDate): string
    {
        try {
            return Carbon::parse($isoDate)->diffForHumans();
        } catch (\Throwable) {
            return 'unknown';
        }
    }
}
