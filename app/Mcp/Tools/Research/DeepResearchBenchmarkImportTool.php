<?php

namespace App\Mcp\Tools\Research;

use App\Domain\Evaluation\Actions\ImportDeepResearchBenchmarkAction;
use App\Domain\Workflow\Models\Workflow;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

/**
 * Seed a FlowEvaluationDataset from the bundled Onyx deep-research benchmark
 * sample, linked to the team's Deep Research workflow, so its quality can be
 * scored via the existing flow-evaluation runner. Dark-shipped behind
 * config('deep_research.enabled').
 */
#[IsDestructive]
class DeepResearchBenchmarkImportTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'deep_research_benchmark_import';

    protected string $description = 'Seed a flow-evaluation dataset from the Onyx deep-research benchmark sample, linked to the Deep Research workflow. Run deep_research_build first. Requires deep research to be enabled.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'workflow_id' => $schema->string()->description('Target workflow UUID (defaults to the team Deep Research workflow)'),
        ];
    }

    public function handle(Request $request): Response
    {
        if (! config('deep_research.enabled')) {
            return $this->failedPreconditionError('Deep Research is disabled. Set DEEP_RESEARCH_ENABLED=true to enable.');
        }

        $teamId = app()->bound('mcp.team_id') ? app('mcp.team_id') : null;
        if (! $teamId) {
            return $this->permissionDeniedError('No team context.');
        }

        $workflowId = $request->get('workflow_id');

        if (! $workflowId) {
            $workflow = Workflow::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->where('name', config('deep_research.workflow_name', 'Deep Research'))
                ->first();

            if ($workflow === null) {
                return $this->failedPreconditionError('No Deep Research workflow found — run deep_research_build first.');
            }

            $workflowId = $workflow->id;
        }

        $dataset = app(ImportDeepResearchBenchmarkAction::class)->execute($teamId, $workflowId);

        return Response::text(json_encode([
            'dataset_id' => $dataset->id,
            'name' => $dataset->name,
            'row_count' => $dataset->row_count,
        ]));
    }
}
