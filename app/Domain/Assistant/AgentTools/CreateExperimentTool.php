<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Experiment\Actions\CreateExperimentAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class CreateExperimentTool implements Tool
{
    public function name(): string
    {
        return 'create_experiment';
    }

    public function description(): string
    {
        return 'Create a new experiment. Track must be one of: growth, retention, revenue, engagement, debug.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required()->description('Experiment title'),
            'thesis' => $schema->string()->description('Experiment hypothesis or objective (default: "To be defined")'),
            'track' => $schema->string()->description('Experiment track: growth, retention, revenue, engagement, debug (default: growth)'),
            'budget_cap_credits' => $schema->string()->description('Budget cap in credits (default: 10000)'),
            'workflow_id' => $schema->string()->description('Optional workflow UUID to materialize into the experiment'),
        ];
    }

    public function handle(Request $request): string
    {
        try {
            $budgetCap = $request->get('budget_cap_credits');

            $experiment = app(CreateExperimentAction::class)->execute(
                userId: auth()->id(),
                title: $request->get('title'),
                thesis: $request->get('thesis', 'To be defined'),
                track: $request->get('track', 'growth'),
                budgetCapCredits: $budgetCap ? (int) $budgetCap : 10000,
                teamId: auth()->user()->current_team_id,
                workflowId: $request->get('workflow_id'),
            );

            return json_encode([
                'success' => true,
                'experiment_id' => $experiment->id,
                'title' => $experiment->title,
                'status' => $experiment->status->value,
                'url' => route('experiments.show', $experiment),
            ]);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }
}
