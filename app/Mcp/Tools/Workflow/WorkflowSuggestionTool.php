<?php

namespace App\Mcp\Tools\Workflow;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Workflow\Actions\SuggestWorkflowAction;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class WorkflowSuggestionTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'workflow_suggest';

    protected string $description = 'Analyze a workflow experiment and get AI-powered optimization suggestions (parallelize steps, switch to cheaper models, replace underperforming skills).';

    public function schema(JsonSchema $schema): array
    {
        return [
            'experiment_id' => $schema->string()
                ->description('ID of the completed or evaluating workflow experiment to analyze.')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['experiment_id' => 'required|string']);
        $experimentId = $validated['experiment_id'];

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }
        $experiment = Experiment::withoutGlobalScopes()->where('team_id', $teamId)->find($experimentId);

        if (! $experiment) {
            return $this->notFoundError('experiment', $experimentId);
        }

        if (! $experiment->hasWorkflow()) {
            return $this->failedPreconditionError('This experiment does not use a workflow. Suggestions are only available for workflow experiments.');
        }

        $suggestions = app(SuggestWorkflowAction::class)->execute($experiment);

        if (empty($suggestions)) {
            return Response::text('No optimization suggestions found. The workflow appears to be well-optimized, or there is not enough execution data yet.');
        }

        $lines = ['Found '.count($suggestions)." optimization suggestion(s) for \"{$experiment->title}\":\n"];

        foreach ($suggestions as $i => $s) {
            $lines[] = ($i + 1).". [{$s['type']}] {$s['reason']}";
            $lines[] = "   Current: {$s['current_value']} → Suggested: {$s['suggested_value']}";
            $lines[] = "   Expected improvement: {$s['expected_improvement']}";
            if (! empty($s['step_id'])) {
                $lines[] = "   Step ID: {$s['step_id']}";
            }
            $lines[] = '';
        }

        return Response::text(implode("\n", $lines));
    }
}
