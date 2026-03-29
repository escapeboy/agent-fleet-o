<?php

namespace App\Domain\Memory\Services;

use App\Domain\Memory\Actions\RetrieveRelevantMemoriesAction;
use App\Domain\Memory\Actions\UnifiedMemorySearchAction;
use App\Domain\Memory\Enums\MemoryTier;
use App\Domain\Memory\Models\Memory;
use Carbon\Carbon;

class MemoryContextInjector
{
    public function __construct(
        private readonly RetrieveRelevantMemoriesAction $retrieveMemories,
        private readonly ?UnifiedMemorySearchAction $unifiedSearch = null,
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
        string $agentId,
        mixed $input,
        ?string $projectId = null,
        ?string $teamId = null,
    ): ?string {
        if (! config('memory.enabled', true) || empty($input)) {
            return null;
        }

        $queryText = is_string($input) ? $input : json_encode($input);

        // Try unified search first (RRF fusion across vector + KG + keyword)
        if ($this->unifiedSearch && config('memory.unified_search.enabled', true) && $teamId) {
            $context = $this->buildUnifiedContext($teamId, $queryText, $agentId, $projectId);
        } else {
            // Fallback to vector-only search
            $context = $this->buildVectorOnlyContext($agentId, $queryText, $projectId, $teamId);
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

        $lessons = Memory::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('tier', MemoryTier::Failures)
            ->orderByDesc('importance')
            ->orderByDesc('created_at')
            ->limit(3)
            ->get(['content', 'source_id', 'created_at']);

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
        string $agentId,
        ?string $projectId,
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

        $lines = $results->map(fn ($item, $i) => $this->formatResultWithAttribution($item, $i + 1))->implode("\n\n");

        return "## Relevant Context\nEach item includes its source and reliability indicators.\n\n{$lines}";
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

        return "{$rank}. [{$attribution}]\n   {$item['content']}";
    }

    /**
     * Build context using vector-only search (legacy fallback).
     */
    private function buildVectorOnlyContext(
        string $agentId,
        string $query,
        ?string $projectId,
        ?string $teamId,
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

            return "{$rank}. [{$attribution}]\n   {$m->content}";
        })->implode("\n\n");

        return "## Relevant Context\nEach item includes its source and reliability indicators.\n\n{$lines}";
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
