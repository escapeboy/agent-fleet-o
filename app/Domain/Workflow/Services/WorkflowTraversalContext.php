<?php

declare(strict_types=1);

namespace App\Domain\Workflow\Services;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use Illuminate\Support\Collection;

/**
 * Mutable traversal state threaded through WorkflowGraphExecutor::resolveNode and
 * its per-node-type handlers. Bundling the (immutable) graph maps with the
 * (mutable) executable/visited accumulators into one object keeps the recursive
 * handlers to two arguments instead of ten — and is shared by handle, so a
 * handler appending to $executable mutates the same collection the caller reads.
 *
 * @property-read array<string, array<string, mixed>> $nodeMap   node id => node snapshot
 * @property-read array<string, array<int, array<string, mixed>>> $edgeMap source node id => outgoing edges
 * @property-read array<string, array<int, string>> $adjacency  node id => successor node ids
 */
final class WorkflowTraversalContext
{
    /**
     * Node ids resolved as executable during traversal (deduplicated by the caller).
     *
     * @var array<int, string>
     */
    public array $executable = [];

    /**
     * Node ids already visited this traversal, to break cycles.
     *
     * @var array<string, bool>
     */
    public array $visited = [];

    /**
     * @param  array<string, mixed>  $nodeMap
     * @param  array<string, mixed>  $edgeMap
     * @param  array<string, mixed>  $adjacency
     * @param  Collection<string, PlaybookStep>  $steps
     */
    public function __construct(
        public readonly array $nodeMap,
        public readonly array $edgeMap,
        public readonly array $adjacency,
        public readonly Collection $steps,
        public readonly Experiment $experiment,
        public readonly int $maxLoopIterations,
    ) {}
}
