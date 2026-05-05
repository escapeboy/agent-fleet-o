<?php

declare(strict_types=1);

namespace App\Domain\KnowledgeGraph\Services;

/**
 * Pure PHP implementation of the Louvain community detection algorithm.
 *
 * Designed for in-memory graphs of hundreds to low thousands of nodes.
 * Treats edges as undirected and unweighted.
 */
class LouvainCommunityDetector
{
    /**
     * Detect communities in the given graph.
     *
     * @param  string[]  $nodes  Array of node IDs
     * @param  array<int, array{0: string, 1: string}>  $edges  Array of [source, target] pairs
     * @return array<string, int> node_id => community_id
     */
    public function detect(array $nodes, array $edges): array
    {
        if (empty($nodes)) {
            return [];
        }

        if (count($nodes) === 1) {
            return [$nodes[0] => 0];
        }

        // Build adjacency with weights (undirected, weight=1 per edge)
        $adj = [];
        $degree = [];
        foreach ($nodes as $n) {
            $adj[$n] = [];
            $degree[$n] = 0;
        }

        foreach ($edges as [$s, $t]) {
            if ($s === $t || ! isset($adj[$s]) || ! isset($adj[$t])) {
                continue;
            }
            if (! isset($adj[$s][$t])) {
                $adj[$s][$t] = 0;
                $adj[$t][$s] = 0;
            }
            $adj[$s][$t]++;
            $adj[$t][$s]++;
            $degree[$s]++;
            $degree[$t]++;
        }

        // Map nodes to sequential integer IDs for efficiency
        $nodeToIdx = array_flip($nodes);
        $idxToNode = $nodes;
        $n = count($nodes);

        // Initial community assignment: each node in its own community
        $community = range(0, $n - 1);

        // Build integer-indexed structures
        $adjIdx = [];
        $degIdx = [];
        foreach ($nodes as $i => $node) {
            $adjIdx[$i] = [];
            $degIdx[$i] = $degree[$node];
            foreach ($adj[$node] as $neighbor => $weight) {
                $adjIdx[$i][$nodeToIdx[$neighbor]] = $weight;
            }
        }

        $this->runLouvainPhases($adjIdx, $degIdx, $community, $n);

        // Map back to string node IDs
        $result = [];
        foreach ($idxToNode as $i => $nodeId) {
            $result[$nodeId] = $community[$i];
        }

        // Re-label communities as sequential integers starting at 0
        $labelMap = [];
        $nextLabel = 0;
        foreach ($result as $nodeId => $communityId) {
            if (! isset($labelMap[$communityId])) {
                $labelMap[$communityId] = $nextLabel++;
            }
            $result[$nodeId] = $labelMap[$communityId];
        }

        return $result;
    }

    /**
     * Run Louvain phases (Phase 1: local optimization, Phase 2: aggregation)
     * on integer-indexed adjacency structures. Modifies $community in place.
     *
     * @param  array<int, array<int, int>>  $adj  adjacency list with weights
     * @param  array<int, int>  $deg  degree of each node
     * @param  array<int, int>  $community  node => community assignment (modified in place)
     */
    private function runLouvainPhases(array $adj, array $deg, array &$community, int $n): void
    {
        // Total weight of edges (each edge counted twice in undirected adj)
        $m = (int) (array_sum($deg) / 2);
        if ($m === 0) {
            return;
        }

        $improved = true;
        $maxOuterLoops = 10;
        $outerLoop = 0;

        while ($improved && $outerLoop < $maxOuterLoops) {
            $outerLoop++;
            $improved = $this->localOptimizationPass($adj, $deg, $community, $n, $m);
        }
    }

    /**
     * Phase 1: local modularity optimization.
     * For each node, try moving it to a neighboring community if ΔQ > 0.
     * Repeat until no improvement.
     *
     * @param  array<int, array<int, int>>  $adj
     * @param  array<int, int>  $deg
     * @param  array<int, int>  $community
     */
    private function localOptimizationPass(
        array $adj,
        array $deg,
        array &$community,
        int $n,
        int $m,
    ): bool {
        $anyImproved = false;

        for ($pass = 0; $pass < 100; $pass++) {
            $moved = false;

            for ($i = 0; $i < $n; $i++) {
                $currentComm = $community[$i];
                $ki = $deg[$i];

                if ($ki === 0) {
                    continue;
                }

                // Sum of edge weights from i to each neighboring community
                $commWeights = [];
                $commTotDeg = [];

                foreach ($adj[$i] as $j => $w) {
                    $cj = $community[$j];
                    $commWeights[$cj] = ($commWeights[$cj] ?? 0) + $w;
                }

                // Compute Σ_tot for each neighboring community (sum of degrees of nodes in that community)
                $neighborComms = array_unique(array_values($community));
                foreach ($neighborComms as $c) {
                    $commTotDeg[$c] = 0;
                }
                for ($j = 0; $j < $n; $j++) {
                    $cj = $community[$j];
                    if (isset($commTotDeg[$cj])) {
                        $commTotDeg[$cj] += $deg[$j];
                    }
                }

                // ΔQ for removing i from its current community
                $kIinCurrent = $commWeights[$currentComm] ?? 0;
                $sigmaTotCurrent = $commTotDeg[$currentComm] - $ki;
                $dqRemove = ($kIinCurrent / $m) - ($sigmaTotCurrent * $ki / (2.0 * $m * $m));

                // Find best community to move to
                $bestComm = $currentComm;
                $bestDq = 0.0;

                foreach ($commWeights as $c => $kIinC) {
                    if ($c === $currentComm) {
                        continue;
                    }
                    $sigmaTotC = $commTotDeg[$c] ?? 0;
                    $dqAdd = ($kIinC / $m) - ($sigmaTotC * $ki / (2.0 * $m * $m));
                    $dq = $dqAdd - $dqRemove;

                    if ($dq > $bestDq) {
                        $bestDq = $dq;
                        $bestComm = $c;
                    }
                }

                if ($bestComm !== $currentComm) {
                    $community[$i] = $bestComm;
                    $moved = true;
                    $anyImproved = true;
                }
            }

            if (! $moved) {
                break;
            }
        }

        return $anyImproved;
    }
}
