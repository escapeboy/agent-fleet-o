<?php

namespace App\Domain\Workflow\Events;

use App\Domain\Workflow\Models\Workflow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after a Workflow is created or updated. Used by Git Sync
 * (build #5, Trendshift top-5 sprint) to push YAML to a linked repository.
 */
class WorkflowSaved
{
    use Dispatchable;

    public function __construct(public readonly Workflow $workflow) {}
}
