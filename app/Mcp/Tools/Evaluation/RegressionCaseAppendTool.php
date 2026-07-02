<?php

namespace App\Mcp\Tools\Evaluation;

use App\Domain\Evaluation\Actions\AppendRegressionCaseAction;
use App\Domain\Evaluation\Enums\EvaluationCaseSource;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class RegressionCaseAppendTool extends Tool
{
    protected string $name = 'regression_case_append';

    protected string $description = 'Append a deferred regression case to the team Production Regressions dataset at triage time (eval-first). The case is non-gating until promoted.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'input' => $schema->string()
                ->description('The input/task that produced the failure')
                ->required(),
            'error_mode' => $schema->string()
                ->description('Short label naming the failure mode (e.g. "hallucinated citation")')
                ->required(),
            'failing_output' => $schema->string()
                ->description('The failing output (excerpt stored in metadata)'),
            'expected_output' => $schema->string()
                ->description('The desired/corrected output, if known'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null) ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::text(json_encode(['error' => 'No current team.']));
        }

        $case = app(AppendRegressionCaseAction::class)->execute(
            teamId: (string) $teamId,
            input: (string) $request->get('input'),
            failingOutput: $request->get('failing_output'),
            errorModeLabel: (string) $request->get('error_mode'),
            source: EvaluationCaseSource::Manual,
            metadata: ['origin' => 'mcp'],
            expectedOutput: $request->get('expected_output'),
            force: true,
        );

        if ($case === null) {
            return Response::text(json_encode(['created' => false, 'reason' => 'duplicate_or_empty']));
        }

        return Response::text(json_encode([
            'created' => true,
            'case_id' => $case->id,
            'dataset_id' => $case->dataset_id,
            'status' => $case->status,
            'error_mode' => $case->error_mode,
        ]));
    }
}
