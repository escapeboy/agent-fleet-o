<?php

namespace App\Domain\Memory\Actions;

use App\Domain\KnowledgeGraph\Models\KgCommunity;
use App\Domain\KnowledgeGraph\Models\KgEdge;
use App\Domain\Memory\Models\Memory;
use App\Domain\Signal\Models\Entity;
use App\Infrastructure\AI\Models\SemanticCacheEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * GDPR right-to-forget: erase a team's memory footprint and every derived
 * projection (knowledge-graph entities/edges/communities, semantic cache) in
 * one transaction, recording an auditable deletion_events row.
 *
 * The cascade is applied at the application level (not relying on DB FKs) so it
 * works identically on Postgres (prod) and SQLite (tests), where FK cascades
 * are not enforced.
 */
class ForgetMemoryAction
{
    /**
     * @return array{memories:int, kg_entities:int, kg_edges:int, kg_communities:int, semantic_cache:int, entity_signal:int}
     */
    public function execute(string $teamId, ?string $agentId = null, ?string $projectId = null, string $reason = 'gdpr_erasure'): array
    {
        return DB::transaction(function () use ($teamId, $agentId, $projectId, $reason): array {
            // --- Memories (scoped to team + optional agent/project) ---
            $memoryQuery = Memory::withoutGlobalScopes()->where('team_id', $teamId);

            if ($agentId !== null) {
                $memoryQuery->where('agent_id', $agentId);
            }

            if ($projectId !== null) {
                $memoryQuery->where('project_id', $projectId);
            }

            $memoriesDeleted = $memoryQuery->delete();

            // Knowledge-graph and semantic cache are team-wide projections with no
            // agent/project dimension, so they are only purged on a full team erasure.
            $entitiesDeleted = 0;
            $edgesDeleted = 0;
            $communitiesDeleted = 0;
            $entitySignalDeleted = 0;
            $cacheDeleted = 0;

            if ($agentId === null && $projectId === null) {
                $entityIds = Entity::withoutGlobalScopes()
                    ->where('team_id', $teamId)
                    ->pluck('id')
                    ->all();

                if (! empty($entityIds)) {
                    // entity_signal has no team_id; clean it via the entities being purged
                    // (covers the case where the FK cascade is not enforced, e.g. SQLite).
                    $entitySignalDeleted = DB::table('entity_signal')
                        ->whereIn('entity_id', $entityIds)
                        ->delete();
                }

                $edgesDeleted = KgEdge::withoutGlobalScopes()
                    ->where('team_id', $teamId)
                    ->delete();

                $entitiesDeleted = Entity::withoutGlobalScopes()
                    ->where('team_id', $teamId)
                    ->delete();

                $communitiesDeleted = KgCommunity::withoutGlobalScopes()
                    ->where('team_id', $teamId)
                    ->delete();

                // Limitation: only this team's rows are purged. Cross-team / platform
                // entries (team_id NULL) are shared cache and are intentionally left.
                $cacheDeleted = SemanticCacheEntry::query()
                    ->where('team_id', $teamId)
                    ->delete();
            }

            $counts = [
                'memories' => $memoriesDeleted,
                'kg_entities' => $entitiesDeleted,
                'kg_edges' => $edgesDeleted,
                'kg_communities' => $communitiesDeleted,
                'semantic_cache' => $cacheDeleted,
                'entity_signal' => $entitySignalDeleted,
            ];

            $scope = $agentId !== null ? 'agent' : ($projectId !== null ? 'project' : 'team');

            DB::table('deletion_events')->insert([
                'id' => (string) Str::uuid7(),
                'team_id' => $teamId,
                'agent_id' => $agentId,
                'scope' => $scope,
                'reason' => $reason,
                'purged_counts' => json_encode($counts),
                'created_at' => now(),
            ]);

            return $counts;
        });
    }
}
