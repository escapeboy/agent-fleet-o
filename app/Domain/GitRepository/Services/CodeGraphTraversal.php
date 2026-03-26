<?php

declare(strict_types=1);

namespace App\Domain\GitRepository\Services;

use App\Domain\GitRepository\Models\CodeElement;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * N-hop traversal of the code_edges directed graph using a recursive CTE on PostgreSQL.
 * Falls back to a PHP-level BFS on SQLite/test environments where CTEs with recursion
 * work but the exact PostgreSQL syntax may differ.
 *
 * The traversal starts from a given CodeElement and follows outgoing edges up to
 * the specified hop depth, optionally filtering by edge type (calls/imports/inherits).
 */
class CodeGraphTraversal
{
    /**
     * Return all CodeElements reachable within $hops steps from $elementId.
     *
     * @return Collection<int, CodeElement>
     */
    public function traverse(
        string $teamId,
        string $elementId,
        int $hops = 2,
        ?string $edgeType = null,
    ): Collection {
        if ($this->isPostgres()) {
            return $this->traverseWithCte($teamId, $elementId, $hops, $edgeType);
        }

        return $this->traverseWithBfs($teamId, $elementId, $hops, $edgeType);
    }

    private function traverseWithCte(
        string $teamId,
        string $elementId,
        int $hops,
        ?string $edgeType,
    ): Collection {
        $edgeFilter = $edgeType !== null ? 'AND e.edge_type = ?' : '';
        // The edge_type filter must appear in BOTH the anchor and the recursive part;
        // omitting it from the recursive step causes traversal to follow edges of the
        // wrong type on hops > 1.
        $bindings = $edgeType !== null
            ? [$elementId, $teamId, $edgeType, $teamId, $edgeType, $hops]
            : [$elementId, $teamId, $teamId, $hops];

        $sql = <<<SQL
        WITH RECURSIVE traversal AS (
            -- Anchor: start from the given element (depth 0)
            SELECT target_id AS element_id, 1 AS depth
            FROM code_edges e
            WHERE e.source_id = ?
              AND e.team_id = ?
              {$edgeFilter}

            UNION

            -- Recursive: follow edges from discovered elements (same edge_type filter)
            SELECT e.target_id, t.depth + 1
            FROM code_edges e
            INNER JOIN traversal t ON e.source_id = t.element_id
            WHERE e.team_id = ?
              {$edgeFilter}
              AND t.depth < ?
        )
        SELECT DISTINCT element_id FROM traversal
        SQL;

        $rows = DB::select($sql, $bindings);
        $ids = array_column($rows, 'element_id');

        if (empty($ids)) {
            return collect();
        }

        return CodeElement::where('team_id', $teamId)
            ->whereIn('id', $ids)
            ->get();
    }

    private function traverseWithBfs(
        string $teamId,
        string $elementId,
        int $hops,
        ?string $edgeType,
    ): Collection {
        $visited = [];
        $frontier = [$elementId];

        for ($depth = 0; $depth < $hops; $depth++) {
            if (empty($frontier)) {
                break;
            }

            $edgesQuery = DB::table('code_edges')
                ->where('team_id', $teamId)
                ->whereIn('source_id', $frontier);

            if ($edgeType !== null) {
                $edgesQuery->where('edge_type', $edgeType);
            }

            $nextIds = $edgesQuery->pluck('target_id')->toArray();
            $nextIds = array_diff($nextIds, array_keys($visited));

            foreach ($nextIds as $id) {
                $visited[$id] = true;
            }

            $frontier = $nextIds;
        }

        if (empty($visited)) {
            return collect();
        }

        return CodeElement::where('team_id', $teamId)
            ->whereIn('id', array_keys($visited))
            ->get();
    }

    private function isPostgres(): bool
    {
        return DB::getDriverName() === 'pgsql';
    }
}
