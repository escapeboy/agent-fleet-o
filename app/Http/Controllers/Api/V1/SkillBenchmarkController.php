<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Skill\Actions\CancelSkillBenchmarkAction;
use App\Domain\Skill\Actions\StartSkillBenchmarkAction;
use App\Domain\Skill\Enums\IterationOutcome;
use App\Domain\Skill\Exceptions\BenchmarkAlreadyRunningException;
use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Models\SkillBenchmark;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @tags Skill Benchmarks
 */
class SkillBenchmarkController extends Controller
{
    /**
     * List benchmarks for a skill.
     */
    public function index(Request $request, Skill $skill): JsonResponse
    {
        $benchmarks = SkillBenchmark::where('skill_id', $skill->id)
            ->where('team_id', $skill->team_id)
            ->orderByDesc('created_at')
            ->cursorPaginate(min((int) $request->input('per_page', 15), 100));

        return response()->json($benchmarks);
    }

    /**
     * Start a new benchmark loop for a skill.
     */
    public function store(
        Request $request,
        Skill $skill,
        StartSkillBenchmarkAction $action,
    ): JsonResponse {
        $request->validate([
            'metric_name' => ['required', 'string', 'max:100'],
            'test_inputs' => ['required', 'array'],
            'metric_direction' => ['sometimes', 'in:maximize,minimize'],
            'time_budget_seconds' => ['sometimes', 'integer', 'min:60'],
            'max_iterations' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'iteration_budget_seconds' => ['sometimes', 'integer', 'min:10'],
            'complexity_penalty' => ['sometimes', 'numeric', 'min:0'],
            'improvement_threshold' => ['sometimes', 'numeric'],
        ]);

        try {
            $benchmark = $action->execute(
                skill: $skill,
                userId: $request->user()->id,
                metricName: $request->input('metric_name'),
                testInputs: $request->input('test_inputs'),
                metricDirection: $request->input('metric_direction', 'maximize'),
                timeBudgetSeconds: (int) $request->input('time_budget_seconds', 3600),
                maxIterations: (int) $request->input('max_iterations', 50),
                iterationBudgetSeconds: (int) $request->input('iteration_budget_seconds', 60),
                complexityPenalty: (float) $request->input('complexity_penalty', 0.01),
                improvementThreshold: (float) $request->input('improvement_threshold', 0.0),
            );
        } catch (BenchmarkAlreadyRunningException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json($benchmark, 201);
    }

    /**
     * Get benchmark status and iteration log.
     */
    public function show(Skill $skill, SkillBenchmark $benchmark): JsonResponse
    {
        abort_if($benchmark->skill_id !== $skill->id || $benchmark->team_id !== $skill->team_id, 404);

        $benchmark->load('iterationLogs');
        $logs = $benchmark->iterationLogs;

        return response()->json([
            'id' => $benchmark->id,
            'skill_id' => $benchmark->skill_id,
            'status' => $benchmark->status->value,
            'metric_name' => $benchmark->metric_name,
            'metric_direction' => $benchmark->metric_direction,
            'baseline_value' => $benchmark->baseline_value,
            'best_value' => $benchmark->best_value,
            'improvement_percent' => $benchmark->improvementPercent(),
            'iteration_count' => $benchmark->iteration_count,
            'max_iterations' => $benchmark->max_iterations,
            'elapsed_seconds' => $benchmark->elapsedSeconds(),
            'time_budget_seconds' => $benchmark->time_budget_seconds,
            'started_at' => $benchmark->started_at?->toIso8601String(),
            'completed_at' => $benchmark->completed_at?->toIso8601String(),
            'stats' => [
                'keep' => $logs->where('outcome', IterationOutcome::Keep)->count(),
                'discard' => $logs->where('outcome', IterationOutcome::Discard)->count(),
                'crash' => $logs->where('outcome', IterationOutcome::Crash)->count(),
                'timeout' => $logs->where('outcome', IterationOutcome::Timeout)->count(),
            ],
            'iteration_logs' => $logs->sortByDesc('iteration_number')->values(),
        ]);
    }

    /**
     * Cancel a running benchmark.
     */
    public function destroy(Skill $skill, SkillBenchmark $benchmark, CancelSkillBenchmarkAction $action): JsonResponse
    {
        abort_if($benchmark->skill_id !== $skill->id || $benchmark->team_id !== $skill->team_id, 404);

        try {
            $benchmark = $action->execute($benchmark);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['status' => $benchmark->status->value]);
    }
}
