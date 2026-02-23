<?php

namespace App\Mcp\Tools\Workflow;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Workflow\Actions\SuggestWorkflowAction;
use Illuminate\Http\Request;
use Laravel\Mcp\Attributes\IsReadOnly;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tool\JsonSchema;
use Laravel\Mcp\Server\Tool\Response;

#[IsReadOnly]
class WorkflowSuggestionTool extends Tool
{
    protected string $name = 'workflow_suggest';

    protected string $description = 'Analyze a workflow experiment and get AI-powered optimization suggestions (parallelize steps, switch to cheaper models, replace underperforming skills).';

    public function schema(JsonSchema $schema): array
    {
        return [
            $schema->string('experiment_id', 'ID of the completed or evaluating workflow experiment to analyze.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $experimentId = $request->input('experiment_id');

        $experiment = Experiment::find($experimentId);

        if (!$experiment) {
            return $this->error("Experiment '{$experimentId}' not found.");
        }

        if (!$experiment->hasWorkflow()) {
            return $this->error('This experiment does not use a workflow. Suggestions are only available for workflow experiments.');
        }

        $suggestions = app(SuggestWorkflowAction::class)->execute($experiment);

        if (empty($suggestions)) {
            return $this->text('No optimization suggestions found. The workflow appears to be well-optimized, or there is not enough execution data yet.');
        }

        $lines = ["Found " . count($suggestions) . " optimization suggestion(s) for \"{$experiment->title}\":\n"];

        foreach ($suggestions as $i => $s) {
            $lines[] = ($i + 1) . ". [{$s['type']}] {$s['reason']}";
            $lines[] = "   Current: {$s['current_value']} → Suggested: {$s['suggested_value']}";
            $lines[] = "   Expected improvement: {$s['expected_improvement']}";
            if (!empty($s['step_id'])) {
                $lines[] = "   Step ID: {$s['step_id']}";
            }
            $lines[] = '';
        }

        return $this->text(implode("\n", $lines));
    }
}
