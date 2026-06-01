<?php

namespace App\Mcp\Tools\Orchestration;

use App\Domain\Crew\Models\Crew;
use App\Domain\Orchestration\Services\OrchestrationCostEstimator;
use App\Domain\Orchestration\Services\OrchestrationCostGate;
use App\Domain\Shared\Models\Team;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class CrewCostEstimateTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'crew_cost_estimate';

    protected string $description = 'Pre-flight cost estimate (in credits) for running a crew: projected fan-out spend across coordinator + QA + workers, the cost-gate threshold, and whether confirmation is required before execution.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'crew_id' => $schema->string()->description('The crew to estimate.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? null;

        if ($teamId === null) {
            return $this->permissionDeniedError('Authentication required.');
        }

        $crewId = $request->get('crew_id');

        if (! is_string($crewId) || $crewId === '') {
            return $this->invalidArgumentError('"crew_id" is required.');
        }

        $crew = Crew::withoutGlobalScopes()->where('team_id', $teamId)->find($crewId);

        if (! $crew) {
            return $this->notFoundError('crew', $crewId);
        }

        $estimator = app(OrchestrationCostEstimator::class);
        $gate = app(OrchestrationCostGate::class);
        $team = Team::withoutGlobalScopes()->find($teamId);

        $projected = $estimator->estimateCrew($crew);

        return Response::text(json_encode([
            'crew_id' => $crew->id,
            'projected_credits' => $projected,
            'threshold_credits' => $gate->thresholdFor($team),
            'gate_enabled' => $gate->enabled(),
            'requires_confirmation' => $gate->requiresConfirmation($projected, $team),
        ]));
    }
}
