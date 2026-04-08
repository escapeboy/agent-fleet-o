<?php

namespace App\Domain\Skill\Services;

use App\Domain\Skill\Enums\BenchmarkStatus;
use App\Domain\Skill\Jobs\SkillImprovementIterationJob;
use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Models\SkillBenchmark;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class MetricGatedImprovementLoopService
{
    /**
     * Start a metric-gated improvement loop for the given skill.
     *
     * Creates a SkillBenchmark record to track the run and dispatches the
     * first SkillImprovementIterationJob, which self-re-dispatches until
     * the stopping conditions (max_iterations or time_budget) are met.
     */
    public function run(Skill $skill, int $maxIterations = 5, string $metric = 'accuracy'): void
    {
        $userId = (string) (Auth::id() ?? $skill->team->users()->orderBy('created_at')->value('id'));

        // Check for an already-running benchmark for this skill+metric to avoid duplicates.
        $running = SkillBenchmark::where('skill_id', $skill->id)
            ->where('metric_name', $metric)
            ->where('status', BenchmarkStatus::Running)
            ->first();

        if ($running) {
            Log::info('MetricGatedImprovementLoopService: loop already running', [
                'skill_id' => $skill->id,
                'benchmark_id' => $running->id,
                'metric' => $metric,
            ]);

            return;
        }

        // Use the latest completed benchmark's best_value as the baseline, or 0.
        $baseline = SkillBenchmark::where('skill_id', $skill->id)
            ->where('metric_name', $metric)
            ->whereNotNull('best_value')
            ->orderByDesc('completed_at')
            ->value('best_value') ?? 0.0;

        $benchmark = SkillBenchmark::create([
            'skill_id' => $skill->id,
            'team_id' => $skill->team_id,
            'metric_name' => $metric,
            'metric_direction' => 'maximize',
            'baseline_value' => $baseline,
            'best_value' => $baseline,
            'iteration_count' => 0,
            'max_iterations' => $maxIterations,
            'time_budget_seconds' => $maxIterations * 300,
            'iteration_budget_seconds' => 300,
            'improvement_threshold' => 0.01,
            'complexity_penalty' => 0.0,
            'status' => BenchmarkStatus::Running,
            'started_at' => now(),
            'test_inputs' => [],
            'settings' => [
                'triggered_by' => 'MetricGatedImprovementLoopService',
            ],
        ]);

        Log::info('MetricGatedImprovementLoopService: dispatching improvement loop', [
            'skill_id' => $skill->id,
            'benchmark_id' => $benchmark->id,
            'metric' => $metric,
            'max_iterations' => $maxIterations,
            'baseline' => $baseline,
        ]);

        SkillImprovementIterationJob::dispatch($benchmark->id, $userId);
    }
}
