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
     * GLOBAL mode: find high-centrality entities using Personalized PageRank,
     * then return memories linked to those entities.
     *
     * @param  string[]  $entityIds  Seed entity UUIDs
     * @return Collection<int, Memory>
     */
    public function globalSearch(string $teamId, array $entityIds, int $topK = 20): Collection
    {
        if (empty($entityIds)) {
            return collect();
        }

        return $this->personalizedPageRank($teamId, $entityIds, topK: $topK);
    }

    /**
     * Personalized PageRank (PPR) over the entity subgraph.
     *
     * Loads up to $maxHops hops from the seed entities, builds an undirected
     * adjacency list, and runs power iteration to convergence.
     *
     * @param  string[]  $seedEntityIds
     * @return Collection<int, Memory>
     */
    public function personalizedPageRank(
        string $teamId,
        array $seedEntityIds,
        float $alpha = 0.85,
        int $maxIterations = 20,
        int $topK = 20,
        int $maxHops = 3,
    ): Collection {
        if (empty($seedEntityIds)) {
            return collect();
        }

        // Collect all node IDs in the subgraph
        $subgraphIds = array_unique(array_merge(
            $seedEntityIds,
            $this->multiHopEntityIds($teamId, $seedEntityIds, $maxHops)->toArray(),
        ));

        if (count($subgraphIds) === 0) {
            return collect();
        }

        // Load all relates_to edges between subgraph nodes
        $edges = DB::table('kg_edges')
            ->where('team_id', $teamId)
            ->whereNull('invalid_at')
            ->where('edge_type', 'relates_to')
            ->whereIn('source_entity_id', $subgraphIds)
            ->whereIn('target_entity_id', $subgraphIds)
            ->select('source_entity_id', 'target_entity_id')
            ->get();

        // Build undirected adjacency list and out-degree
        $adj = [];
        $degree = [];
        foreach ($subgraphIds as $id) {
            $adj[$id] = [];
            $degree[$id] = 0;
        }

        foreach ($edges as $edge) {
            $s = $edge->source_entity_id;
            $t = $edge->target_entity_id;
            if ($s === $t) {
                continue;
            }
            if (! isset($adj[$s][$t])) {
                $adj[$s][$t] = true;
                $degree[$s]++;
            }
            if (! isset($adj[$t][$s])) {
                $adj[$t][$s] = true;
                $degree[$t]++;
            }
        }

        $nodeCount = count($subgraphIds);
        $seedSet = array_flip($seedEntityIds);
        $seedCount = count($seedEntityIds);
        $personalization = 1.0 / $seedCount;

        // Initialize scores
        $scores = [];
        foreach ($subgraphIds as $id) {
            $scores[$id] = isset($seedSet[$id]) ? $personalization : 0.0;
        }

        // Power iteration
        for ($iter = 0; $iter < $maxIterations; $iter++) {
            $newScores = [];
            foreach ($subgraphIds as $id) {
                $newScores[$id] = (1.0 - $alpha) * (isset($seedSet[$id]) ? $personalization : 0.0);
            }

            foreach ($subgraphIds as $u) {
                $degU = $degree[$u];
                if ($degU === 0 || $scores[$u] == 0.0) {
                    continue;
                }
                $contribution = $alpha * $scores[$u] / $degU;
                foreach (array_keys($adj[$u]) as $v) {
                    $newScores[$v] += $contribution;
                }
            }

            // Check convergence
            $delta = 0.0;
            foreach ($subgraphIds as $id) {
                $delta += abs($newScores[$id] - $scores[$id]);
            }
            $scores = $newScores;

            if ($delta < 1e-6) {
                break;
            }
        }

        // Sort descending, take topK
        arsort($scores);
        $topEntities = array_keys(array_slice($scores, 0, $topK, true));

        if (empty($topEntities)) {
            return collect();
        }

        return $this->memoriesForEntities($teamId, $topEntities);
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
