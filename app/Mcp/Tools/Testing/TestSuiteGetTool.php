<?php

namespace App\Mcp\Tools\Testing;

use App\Domain\Testing\Models\TestRun;
use App\Domain\Testing\Models\TestSuite;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class TestSuiteGetTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'test_suite_get';

    protected string $description = 'Get one test suite and its test runs. Returns suite config plus each run\'s status, score, experiment_id, duration, and timestamps.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'test_suite_id' => $schema->string()
                ->description('The test suite UUID')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['test_suite_id' => 'required|string']);

        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null) ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $suite = TestSuite::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($validated['test_suite_id']);

        if (! $suite) {
            return $this->notFoundError('test_suite');
        }

        // TestRun has no team_id; constrain via the team-scoped suite.
        $runs = TestRun::where('test_suite_id', $suite->id)
            ->orderByDesc('created_at')
            ->get();

        return Response::text(json_encode([
            'id' => $suite->id,
            'name' => $suite->name,
            'project_id' => $suite->project_id,
            'strategy' => $suite->test_strategy?->value,
            'active' => $suite->is_active,
            'quality_threshold' => $suite->quality_threshold,
            'pass_rate' => $suite->pass_rate,
            'last_run_at' => $suite->last_run_at?->toIso8601String(),
            'run_count' => $runs->count(),
            'runs' => $runs->map(fn (TestRun $r) => [
                'id' => $r->id,
                'experiment_id' => $r->experiment_id,
                'status' => $r->status?->value,
                'score' => $r->score,
                'duration_ms' => $r->duration_ms,
                'started_at' => $r->started_at?->toIso8601String(),
                'completed_at' => $r->completed_at?->toIso8601String(),
            ])->toArray(),
        ]));
    }
}
