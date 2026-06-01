<?php

namespace App\Mcp\Tools\Orchestration;

use App\Domain\Orchestration\Services\OrchestrationTierRecommender;
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
class OrchestrationRecommendTierTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'orchestration_recommend_tier';

    protected string $description = 'Recommend an orchestration shape for a goal — single agent, crew (with a process type), or workflow — with reasoning and confidence. Recommendation only; it never executes anything. Use before building a crew or workflow to pick the right structure.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'goal' => $schema->string()->description('The goal/task to orchestrate.'),
            'needs_parallel' => $schema->boolean()->nullable()->description('Hint: the work benefits from parallel agents.'),
            'stages' => $schema->integer()->nullable()->description('Hint: number of sequential stages (>1 favours a workflow).'),
            'subtasks' => $schema->integer()->nullable()->description('Hint: number of subtasks (sizes a crew).'),
        ];
    }

    public function handle(Request $request): Response
    {
        if (! config('orchestration.tier_selector.enabled', false)) {
            return $this->failedPreconditionError('The orchestration tier selector is not enabled.');
        }

        $goal = $request->get('goal');

        if (! is_string($goal) || trim($goal) === '') {
            return $this->invalidArgumentError('"goal" is required.');
        }

        $signals = array_filter([
            'needs_parallel' => $request->get('needs_parallel'),
            'stages' => $request->get('stages'),
            'subtasks' => $request->get('subtasks'),
        ], fn ($v) => $v !== null);

        return Response::text(json_encode(
            app(OrchestrationTierRecommender::class)->recommend($goal, $signals),
        ));
    }
}
