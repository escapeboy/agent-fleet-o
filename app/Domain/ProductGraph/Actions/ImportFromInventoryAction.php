<?php

namespace App\Domain\ProductGraph\Actions;

use App\Domain\ProductGraph\Enums\EdgeType;
use App\Domain\ProductGraph\Enums\NodeStatus;
use App\Domain\ProductGraph\Enums\NodeType;

/**
 * Seeds an initial product graph from the FleetQ feature-inventory markdown.
 *
 * Pure string parsing (no LLM — "the map, not the territory"). Tolerates format
 * drift: unparseable lines are skipped rather than aborting the import. Idempotent
 * via {@see CreateNodeAction}/{@see UpsertEdgeAction}, so re-running is safe.
 *
 * It extracts two things:
 *   1. The "base domain list" comma line  → one `feature` node per domain (implemented),
 *      each `part_of` a root `product` node.
 *   2. The "BACKLOG" table rows           → `feature` nodes (planned), tagged `backlog`.
 */
class ImportFromInventoryAction
{
    public function __construct(
        private readonly CreateNodeAction $createNode,
        private readonly UpsertEdgeAction $upsertEdge,
    ) {}

    /**
     * @return array{nodes_created: int, edges_created: int}
     */
    public function execute(string $teamId, string $markdown, string $rootName = 'Platform'): array
    {
        $nodesCreated = 0;
        $edgesCreated = 0;

        $root = $this->createNode->execute(
            teamId: $teamId,
            type: NodeType::Product,
            name: $rootName,
            status: NodeStatus::Implemented,
            description: 'Root product node (imported from feature inventory).',
            tags: ['imported'],
        );
        $nodesCreated += $root->wasRecentlyCreated ? 1 : 0;

        foreach ($this->parseDomains($markdown) as $domain) {
            $node = $this->createNode->execute(
                teamId: $teamId,
                type: NodeType::Feature,
                name: $domain,
                status: NodeStatus::Implemented,
                tags: ['domain', 'imported'],
            );
            $nodesCreated += $node->wasRecentlyCreated ? 1 : 0;

            $edge = $this->upsertEdge->execute($teamId, $node->id, $root->id, EdgeType::PartOf);
            $edgesCreated += $edge->wasRecentlyCreated ? 1 : 0;
        }

        foreach ($this->parseBacklog($markdown) as $item) {
            $tags = ['backlog', 'imported'];
            if ($item['domain'] !== '') {
                $tags[] = $item['domain'];
            }

            $node = $this->createNode->execute(
                teamId: $teamId,
                type: NodeType::Feature,
                name: $item['name'],
                status: NodeStatus::Planned,
                tags: $tags,
            );
            $nodesCreated += $node->wasRecentlyCreated ? 1 : 0;

            $edge = $this->upsertEdge->execute($teamId, $node->id, $root->id, EdgeType::PartOf);
            $edgesCreated += $edge->wasRecentlyCreated ? 1 : 0;
        }

        return ['nodes_created' => $nodesCreated, 'edges_created' => $edgesCreated];
    }

    /**
     * Extract domain names from a "... domain list: A, B, C" line.
     *
     * @return string[]
     */
    private function parseDomains(string $markdown): array
    {
        if (! preg_match('/(?:base )?domain list[^:]*:\s*(.+)/i', $markdown, $m)) {
            return [];
        }

        $tokens = array_map('trim', explode(',', $m[1]));

        return array_values(array_filter($tokens, function (string $t): bool {
            // Domain names are single PascalCase identifiers; reject prose/markdown.
            return $t !== '' && preg_match('/^[A-Za-z][A-Za-z0-9 ]{0,38}$/', $t) === 1
                && ! str_contains($t, '(') && ! str_contains($t, '*');
        }));
    }

    /**
     * Extract backlog capability rows from the markdown table under a BACKLOG heading.
     *
     * @return list<array{name: string, domain: string}>
     */
    private function parseBacklog(string $markdown): array
    {
        $lines = preg_split('/\r?\n/', $markdown) ?: [];
        $inBacklog = false;
        $items = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (preg_match('/^#{1,6}.*backlog/i', $trimmed)) {
                $inBacklog = true;

                continue;
            }

            // A new heading ends the backlog section.
            if ($inBacklog && preg_match('/^#{1,6}\s/', $trimmed) && ! preg_match('/backlog/i', $trimmed)) {
                break;
            }

            if (! $inBacklog || ! str_starts_with($trimmed, '|')) {
                continue;
            }

            $cells = array_map('trim', explode('|', trim($trimmed, '|')));

            // Expected: # | Capability | Domain | exists | missing | Priority
            if (count($cells) < 3) {
                continue;
            }

            // Skip header + separator rows.
            if (str_contains(strtolower($cells[1]), 'capability') || str_contains($cells[0], '---')) {
                continue;
            }

            if (! preg_match('/\*\*(.+?)\*\*/', $cells[1], $nameMatch)) {
                continue;
            }

            $name = trim(preg_replace('/\s*\(.+?\)\s*/', '', $nameMatch[1]) ?? $nameMatch[1]);
            if ($name === '') {
                continue;
            }

            $items[] = ['name' => $name, 'domain' => $cells[2]];
        }

        return $items;
    }
}
