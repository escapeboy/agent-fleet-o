<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Workflow\Actions\ValidateWorkflowGraphAction;
use App\Domain\Workflow\Models\Workflow;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class ActivateWorkflowTool implements Tool
{
    public function name(): string
    {
        return 'activate_workflow';
    }

    public function description(): string
    {
        return 'Validate and activate a workflow so it can be used in experiments and projects. The graph must have valid start/end nodes.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'workflow_id' => $schema->string()->required()->description('The workflow UUID'),
        ];
    }

    public function handle(Request $request): string
    {
        $workflow = Workflow::find($request->get('workflow_id'));
        if (! $workflow) {
            return json_encode(['error' => 'Workflow not found']);
        }

        try {
            $result = app(ValidateWorkflowGraphAction::class)->execute($workflow, activateIfValid: true);

            if (! $result['valid']) {
                return json_encode(['error' => 'Workflow graph is invalid: '.implode(', ', $result['errors'])]);
            }

            $workflow->refresh();

            return json_encode([
                'success' => true,
                'workflow_id' => $workflow->id,
                'name' => $workflow->name,
                'status' => $workflow->status->value,
            ]);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }
}
