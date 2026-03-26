<?php

declare(strict_types=1);

namespace App\Domain\KnowledgeGraph\Services;

use App\Domain\Memory\Models\Memory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Graph traversal service implementing LightRAG-style local/global retrieval modes.
 *
 * Local mode  — entity-focused: traverse 1+ hops from seed entities, then fetch
 *               memories linked via 'contains' edges.
 *
 * Global mode — centrality-focused: find the most well-connected entities related
 *               to seed entities (high edge count), then fetch their linked memories.
 *
 * Source provenance — given an entity UUID, return the Memory records that contain it
 *                     via 'contains' edges (source_node_type='chunk').
 *
 * All queries are strictly scoped to team_id for multi-tenant isolation.
 */
class KnowledgeGraphTraversal
{
    /**
     * LOCAL mode: traverse the graph outward from $entityIds and return linked memories.
     *
     * For hops=1 a simple flat JOIN is used for performance. For hops>1 a recursive
     * CTE expands the frontier to the requested depth before collecting memories.
     *
     * @param  string[]  $entityIds  UUIDs of seed entities
     * @return Collection<int, Memory>
     */
    public function localSearch(string $teamId, array $entityIds, int $hops = 1): Collection
    {
        if (empty($entityIds)) {
            return collect();
        }

        $relatedEntityIds = $hops === 1
            ? $this->oneHopEntityIds($teamId, $entityIds)
            : $this->multiHopEntityIds($teamId, $entityIds, $hops);

        $allEntityIds = array_unique(array_merge($entityIds, $relatedEntityIds->toArray()));

        return $this->memoriesForEntities($teamId, $allEntityIds);
    }

    /**
     * GLOBAL mode: find high-centrality entities (ranked by edge count) connected to
     * $entityIds, then return memories linked to those entities.
     *
     * @param  string[]  $entityIds  Seed entity UUIDs
     * @return Collection<int, Memory>
     */
    public function globalSearch(string $teamId, array $entityIds, int $topK = 20): Collection
    {
        if (empty($entityIds)) {
            return collect();
        }

        $highCentrality = DB::table('kg_edges')
            ->where('team_id', $teamId)
            ->whereNull('invalid_at')
            ->whereIn('source_entity_id', $entityIds)
            ->where('edge_type', 'relates_to')
            ->select('target_entity_id', DB::raw('COUNT(*) as edge_count'))
            ->groupBy('target_entity_id')
            ->orderByDesc('edge_count')
            ->limit($topK)
            ->pluck('target_entity_id');

        if ($highCentrality->isEmpty()) {
            return collect();
        }

        return $this->memoriesForEntities($teamId, $highCentrality->toArray());
    }

    /**
     * SOURCE PROVENANCE: given an entity UUID, return the Memory records that contain it.
     *
     * Uses 'contains' edges where source_node_type='chunk' (Memory UUID as source_entity_id)
     * and target_node_type='entity'.
     *
     * @return Collection<int, Memory>
     */
    public function sourceProvenance(string $teamId, string $entityId): Collection
    {
        $memoryIds = DB::table('kg_edges')
            ->where('team_id', $teamId)
            ->where('edge_type', 'contains')
            ->where('source_node_type', 'chunk')
            ->where('target_node_type', 'entity')
            ->where('target_entity_id', $entityId)
            ->pluck('source_entity_id');

        if ($memoryIds->isEmpty()) {
            return collect();
        }

        return Memory::where('team_id', $teamId)
            ->whereIn('id', $memoryIds)
            ->get();
    }

    /**
     * Single-hop traversal: fetch target entity IDs reachable from $entityIds via
     * 'relates_to' edges in one step.
     *
     * @param  string[]  $entityIds
     * @return Collection<int, string>
     */
    private function oneHopEntityIds(string $teamId, array $entityIds): Collection
    {
        return DB::table('kg_edges')
            ->where('team_id', $teamId)
            ->whereNull('invalid_at')
            ->whereIn('source_entity_id', $entityIds)
            ->where('edge_type', 'relates_to')
            ->pluck('target_entity_id');
    }

    /**
     * Multi-hop traversal via a PostgreSQL recursive CTE.
     *
     * The CTE expands up to $maxHops levels from the seed set, collecting all reachable
     * entity IDs through 'relates_to' edges.
     *
     * @param  string[]  $entityIds
     * @return Collection<int, string>
     */
    private function multiHopEntityIds(string $teamId, array $entityIds, int $maxHops): Collection
    {
        // Build the IN placeholder list for the seed set
        $placeholders = implode(',', array_fill(0, count($entityIds), '?'));

        $sql = "
            WITH RECURSIVE entity_hops AS (
                SELECT target_entity_id AS entity_id, 1 AS depth
                FROM kg_edges
                WHERE source_entity_id IN ({$placeholders})
                  AND team_id = ?
                  AND invalid_at IS NULL
                  AND edge_type = 'relates_to'

                UNION

                SELECT ke.target_entity_id, eh.depth + 1
                FROM kg_edges ke
                INNER JOIN entity_hops eh ON ke.source_entity_id = eh.entity_id
                WHERE ke.team_id = ?
                  AND ke.invalid_at IS NULL
                  AND ke.edge_type = 'relates_to'
                  AND eh.depth < ?
            )
            SELECT DISTINCT entity_id FROM entity_hops
        ";

        $bindings = array_merge($entityIds, [$teamId, $teamId, $maxHops]);

        $rows = DB::select($sql, $bindings);

        return collect($rows)->pluck('entity_id');
    }

    /**
     * Fetch Memory records whose IDs appear as source_entity_id in 'contains' edges
     * targeting any of the given entity IDs.
     *
     * @param  string[]  $entityIds
     * @return Collection<int, Memory>
     */
    private function memoriesForEntities(string $teamId, array $entityIds): Collection
    {
        if (empty($entityIds)) {
            return collect();
        }

        $memoryIds = DB::table('kg_edges')
            ->where('team_id', $teamId)
            ->where('edge_type', 'contains')
            ->where('target_node_type', 'entity')
            ->whereIn('target_entity_id', $entityIds)
            ->pluck('source_entity_id');

        if ($memoryIds->isEmpty()) {
            return collect();
        }

        return Memory::where('team_id', $teamId)
            ->whereIn('id', $memoryIds)
            ->get();
    }
}
