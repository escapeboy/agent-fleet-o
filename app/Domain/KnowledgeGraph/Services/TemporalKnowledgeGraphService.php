<?php

namespace App\Domain\KnowledgeGraph\Services;

use App\Domain\KnowledgeGraph\Models\KgEdge;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class TemporalKnowledgeGraphService
{
    /**
     * All currently valid facts for an entity (outgoing edges, invalid_at IS NULL).
     */
    public function getCurrentFacts(string $teamId, string $entityId): Collection
    {
        return KgEdge::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('source_entity_id', $entityId)
            ->whereNull('invalid_at')
            ->with(['sourceEntity', 'targetEntity'])
            ->latest('valid_at')
            ->get();
    }

    /**
     * Full fact timeline for an entity — includes historical (invalidated) edges.
     */
    public function getEntityTimeline(string $teamId, string $entityId): Collection
    {
        return KgEdge::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where(function ($q) use ($entityId) {
                $q->where('source_entity_id', $entityId)
                    ->orWhere('target_entity_id', $entityId);
            })
            ->with(['sourceEntity', 'targetEntity'])
            ->orderByDesc('valid_at')
            ->get();
    }

    /**
     * Point-in-time query: facts that were valid at a specific moment.
     */
    public function getFactsAt(string $teamId, string $entityId, Carbon $at): Collection
    {
        return KgEdge::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('source_entity_id', $entityId)
            ->where(function ($q) use ($at) {
                $q->whereNull('valid_at')->orWhere('valid_at', '<=', $at);
            })
            ->where(function ($q) use ($at) {
                $q->whereNull('invalid_at')->orWhere('invalid_at', '>', $at);
            })
            ->with(['sourceEntity', 'targetEntity'])
            ->get();
    }

    /**
     * Semantic search over current facts using cosine similarity.
     * Requires pgvector; returns empty collection gracefully if not available.
     *
     * @return Collection<int, KgEdge>
     */
    public function search(
        string $teamId,
        string $queryEmbedding,
        ?string $relationType = null,
        ?string $entityType = null,
        bool $includeHistory = false,
        float $threshold = 0.7,
        int $limit = 10,
    ): Collection {
        try {
            $query = KgEdge::withoutGlobalScopes()
                ->select('kg_edges.*')
                ->selectRaw('1 - (fact_embedding <=> ?) AS similarity', [$queryEmbedding])
                ->where('team_id', $teamId)
                ->whereRaw('1 - (fact_embedding <=> ?) >= ?', [$queryEmbedding, $threshold])
                ->with(['sourceEntity', 'targetEntity'])
                ->orderByDesc('similarity');

            if (! $includeHistory) {
                $query->whereNull('invalid_at');
            }

            if ($relationType) {
                $query->where('relation_type', $relationType);
            }

            if ($entityType) {
                $query->whereHas('sourceEntity', fn ($q) => $q->where('type', $entityType));
            }

            return $query->limit($limit)->get();
        } catch (\Throwable) {
            return collect();
        }
    }

    /**
     * Build a markdown context block of the most relevant current facts for agent injection.
     */
    public function buildContext(string $teamId, string $queryEmbedding, int $limit = 10): ?string
    {
        $facts = $this->search($teamId, $queryEmbedding, limit: $limit);

        if ($facts->isEmpty()) {
            return null;
        }

        $lines = $facts->map(function (KgEdge $edge) {
            $source = $edge->sourceEntity?->name ?? 'Unknown';
            $target = $edge->targetEntity?->name ?? 'Unknown';
            $since = $edge->valid_at ? ' (since '.$edge->valid_at->format('F Y').')' : '';

            return "- {$edge->fact} [{$source} → {$target}]{$since}";
        })->join("\n");

        return "## Current Entity Facts\n{$lines}";
    }
}
