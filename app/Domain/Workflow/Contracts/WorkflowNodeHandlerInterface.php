<?php

namespace App\Domain\Workflow\Contracts;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Workflow\DTOs\WorkflowNodeDefinition;

interface WorkflowNodeHandlerInterface
{
    /**
     * Return the node definition (type, label, icon, etc.) for this handler.
     */
    public static function definition(): WorkflowNodeDefinition;

    /**
     * Execute this node's logic.
     *
     * Called synchronously by WorkflowNodeDispatcher.
     * Must update $step->status to 'completed' or 'failed' before returning.
     */
    public function handle(PlaybookStep $step, Experiment $experiment, array $nodeData): void;
}
