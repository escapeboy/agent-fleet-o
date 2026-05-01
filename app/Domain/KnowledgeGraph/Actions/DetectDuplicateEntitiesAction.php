<?php

declare(strict_types=1);

namespace App\Domain\KnowledgeGraph\Actions;

use App\Domain\Signal\Models\Entity;

class DetectDuplicateEntitiesAction
{
    /**
     * Detect near-duplicate Entity records using string similarity.
     *
     * Returns candidates sorted by confidence descending, capped at 50.
     *
     * @return array<int, array{canonical_id: string, duplicate_id: string, confidence: float, reason: string}>
     */
    public function execute(string $teamId, float $similarityThreshold = 0.85): array
    {
        $entities = Entity::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->select(['id', 'name', 'canonical_name', 'type', 'mention_count'])
            ->get();

        // Group by type — only compare within the same type
        $byType = [];
        foreach ($entities as $entity) {
            $byType[$entity->type][] = $entity;
        }

        $candidates = [];

        foreach ($byType as $type => $group) {
            // Cap per-type to 500 to avoid timeout on huge graphs
            if (count($group) > 500) {
                $group = array_slice($group, 0, 500);
            }

            $count = count($group);
            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $a = $group[$i];
                    $b = $group[$j];

                    // Skip pairs where both are high-volume (likely well-established distinct entities)
                    if ($a->mention_count > 1000 && $b->mention_count > 1000) {
                        continue;
                    }

                    similar_text(
                        strtolower($a->name),
                        strtolower($b->name),
                        $percent,
                    );
                    $similarity = $percent / 100.0;

                    if ($similarity >= $similarityThreshold) {
                        // Canonical = higher mention count
                        if ($a->mention_count >= $b->mention_count) {
                            $canonical = $a;
                            $duplicate = $b;
                        } else {
                            $canonical = $b;
                            $duplicate = $a;
                        }

                        $candidates[] = [
                            'canonical_id' => $canonical->id,
                            'duplicate_id' => $duplicate->id,
                            'confidence' => round($similarity, 4),
                            'reason' => sprintf('Name similarity %.1f%% between "%s" and "%s" (type: %s)', $percent, $canonical->name, $duplicate->name, $type),
                        ];
                    }
                }
            }
        }

        // Sort by confidence descending
        usort($candidates, fn ($a, $b) => $b['confidence'] <=> $a['confidence']);

        return array_slice($candidates, 0, 50);
    }
}
