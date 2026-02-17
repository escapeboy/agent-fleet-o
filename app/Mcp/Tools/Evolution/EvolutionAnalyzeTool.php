<?php

namespace App\Mcp\Tools\Evolution;

use App\Domain\Agent\Models\Agent;
use App\Domain\Evolution\Actions\AnalyzeExecutionForEvolutionAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class EvolutionAnalyzeTool extends Tool
{
    protected string $name = 'evolution_analyze';

    protected string $description = 'Analyze an agent\'s performance and generate an evolution proposal with suggested improvements.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()
                ->description('The agent ID to analyze')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $agent = Agent::findOrFail($request->get('agent_id'));
        $latestExecution = $agent->executions()->latest()->first();

        $proposal = app(AnalyzeExecutionForEvolutionAction::class)->execute(
            $agent,
            $latestExecution,
        );

        return Response::text(json_encode([
            'id' => $proposal->id,
            'status' => $proposal->status->value,
            'analysis' => $proposal->analysis,
            'proposed_changes' => $proposal->proposed_changes,
            'reasoning' => $proposal->reasoning,
            'confidence_score' => $proposal->confidence_score,
        ]));
    }
}
