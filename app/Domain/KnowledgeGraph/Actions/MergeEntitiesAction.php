<?php

declare(strict_types=1);

namespace App\Domain\KnowledgeGraph\Actions;

use App\Domain\KnowledgeGraph\Models\KgEdge;
use App\Domain\Signal\Models\Entity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MergeEntitiesAction
{
    /**
     * Merge a duplicate entity into the canonical entity.
     *
     * Redirects all kg_edges and signal associations from the duplicate to the
     * canonical entity, sums mention counts, then deletes the duplicate.
     */
    public function execute(string $teamId, string $canonicalEntityId, string $duplicateEntityId): void
    {
        $canonical = Entity::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('id', $canonicalEntityId)
            ->firstOrFail();

        $duplicate = Entity::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('id', $duplicateEntityId)
            ->firstOrFail();

        DB::transaction(function () use ($canonical, $duplicate, $teamId): void {
            // Redirect kg_edges source
            KgEdge::where('team_id', $teamId)
                ->where('source_entity_id', $duplicate->id)
                ->update(['source_entity_id' => $canonical->id]);

            // Redirect kg_edges target
            KgEdge::where('team_id', $teamId)
                ->where('target_entity_id', $duplicate->id)
                ->update(['target_entity_id' => $canonical->id]);

            // Redirect entity_signal pivot — skip rows that would conflict
            $signalIds = DB::table('entity_signal')
                ->where('entity_id', $duplicate->id)
                ->pluck('signal_id');

            foreach ($signalIds as $signalId) {
                $exists = DB::table('entity_signal')
                    ->where('entity_id', $canonical->id)
                    ->where('signal_id', $signalId)
                    ->exists();

                if (! $exists) {
                    DB::table('entity_signal')
                        ->where('entity_id', $duplicate->id)
                        ->where('signal_id', $signalId)
                        ->update(['entity_id' => $canonical->id]);
                } else {
                    DB::table('entity_signal')
                        ->where('entity_id', $duplicate->id)
                        ->where('signal_id', $signalId)
                        ->delete();
                }
            }

            // Sum mention counts and update last_seen_at
            $canonical->mention_count += $duplicate->mention_count;
            if ($duplicate->last_seen_at && (! $canonical->last_seen_at || $duplicate->last_seen_at->gt($canonical->last_seen_at))) {
                $canonical->last_seen_at = $duplicate->last_seen_at;
            }
            $canonical->save();

            // Delete duplicate
            $duplicate->delete();
        });

        Log::info('MergeEntitiesAction: entities merged', [
            'team_id' => $teamId,
            'canonical_id' => $canonicalEntityId,
            'duplicate_id' => $duplicateEntityId,
            'canonical_name' => $canonical->name,
            'duplicate_name' => $duplicate->name,
        ]);
    }
}
