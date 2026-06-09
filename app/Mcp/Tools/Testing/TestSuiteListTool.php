<?php

namespace App\Mcp\Tools\Testing;

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
class TestSuiteListTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'test_suite_list';

    protected string $description = 'List the team\'s test suites. Returns id, name, project_id, test strategy, active flag, quality threshold, pass rate, and run count.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null) ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $suites = TestSuite::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->withCount('testRuns')
            ->orderByDesc('created_at')
            ->get();

        return Response::text(json_encode([
            'count' => $suites->count(),
            'test_suites' => $suites->map(fn (TestSuite $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'project_id' => $s->project_id,
                'strategy' => $s->test_strategy?->value,
                'active' => $s->is_active,
                'quality_threshold' => $s->quality_threshold,
                'pass_rate' => $s->pass_rate,
                'run_count' => $s->test_runs_count,
                'last_run_at' => $s->last_run_at?->toIso8601String(),
            ])->toArray(),
        ]));
    }
}
