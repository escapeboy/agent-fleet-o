<?php

namespace App\Mcp\Tools\Skill;

use App\Domain\Skill\Actions\StartSkillBenchmarkAction;
use App\Domain\Skill\Exceptions\BenchmarkAlreadyRunningException;
use App\Domain\Skill\Models\Skill;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class SkillBenchmarkStartTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'skill_benchmark_start';

    protected string $description = 'Start an autonomous metric-gated improvement loop for a skill. The loop generates candidate versions, measures the metric, and keeps or discards changes automatically until the time budget or iteration limit is reached.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'skill_id' => $schema->string()
                ->description('The skill UUID to benchmark')
                ->required(),
            'metric_name' => $schema->string()
                ->description('Metric to optimise: "latency_ms", "output_length", "json:<dot.path>", or "regex:<pattern>"')
                ->required(),
            'test_inputs' => $schema->array()
                ->description('Locked oracle test inputs — an array of input objects matching the skill input_schema')
                ->required(),
            'metric_direction' => $schema->string()
                ->description('Whether to maximize or minimize the metric (default: maximize)')
                ->enum(['maximize', 'minimize']),
            'time_budget_seconds' => $schema->integer()
                ->description('Total wall-clock budget for the loop in seconds (default: 3600)'),
            'max_iterations' => $schema->integer()
                ->description('Maximum number of iterations (default: 50)'),
            'iteration_budget_seconds' => $schema->integer()
                ->description('Per-iteration time cap in seconds (default: 60)'),
            'complexity_penalty' => $schema->number()
                ->description('Penalty per token of complexity increase (default: 0.01)'),
            'improvement_threshold' => $schema->number()
                ->description('Minimum effective improvement to keep a version (default: 0.0)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'skill_id' => 'required|string',
            'metric_name' => 'required|string',
            'test_inputs' => 'required|array',
            'metric_direction' => 'sometimes|in:maximize,minimize',
            'time_budget_seconds' => 'sometimes|integer|min:60',
            'max_iterations' => 'sometimes|integer|min:1|max:500',
            'iteration_budget_seconds' => 'sometimes|integer|min:10',
            'complexity_penalty' => 'sometimes|numeric|min:0',
            'improvement_threshold' => 'sometimes|numeric',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $skill = Skill::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($validated['skill_id']);

        if (! $skill) {
            return $this->notFoundError('skill');
        }

        $userId = auth()->id() ?? $skill->team?->owner->id ?? '';

        try {
            $benchmark = app(StartSkillBenchmarkAction::class)->execute(
                skill: $skill,
                userId: $userId,
                metricName: $validated['metric_name'],
                testInputs: $validated['test_inputs'],
                metricDirection: $validated['metric_direction'] ?? 'maximize',
                timeBudgetSeconds: $validated['time_budget_seconds'] ?? 3600,
                maxIterations: $validated['max_iterations'] ?? 50,
                iterationBudgetSeconds: $validated['iteration_budget_seconds'] ?? 60,
                complexityPenalty: $validated['complexity_penalty'] ?? 0.01,
                improvementThreshold: $validated['improvement_threshold'] ?? 0.0,
            );
        } catch (BenchmarkAlreadyRunningException $e) {
            throw $e;
        }

        return Response::text(json_encode([
            'benchmark_id' => $benchmark->id,
            'status' => $benchmark->status->value,
            'metric_name' => $benchmark->metric_name,
            'baseline_value' => $benchmark->baseline_value,
            'max_iterations' => $benchmark->max_iterations,
            'time_budget_seconds' => $benchmark->time_budget_seconds,
            'started_at' => $benchmark->started_at?->toIso8601String(),
            'message' => 'Benchmark started. Use skill_benchmark_status to monitor progress.',
        ]));
    }
}
