<?php

namespace App\Domain\Skill\Actions;

use App\Domain\Skill\Enums\BenchmarkStatus;
use App\Domain\Skill\Exceptions\BenchmarkAlreadyRunningException;
use App\Domain\Skill\Jobs\SkillImprovementIterationJob;
use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Models\SkillBenchmark;
use App\Domain\Skill\Models\SkillExecution;
use App\Domain\Skill\Models\SkillVersion;
use Illuminate\Support\Facades\DB;

class StartSkillBenchmarkAction
{
    public function __construct(
        private readonly ExecuteSkillAction $executeSkill,
        private readonly MeasureSkillMetricAction $measureMetric,
    ) {}

    /**
     * @param  array<int, mixed>  $testInputs  Locked oracle — agent cannot modify
     * @param  array<string, mixed>  $settings  Optional overrides (model, temperature, etc.)
     */
    public function execute(
        Skill $skill,
        string $userId,
        string $metricName,
        array $testInputs,
        string $metricDirection = 'maximize',
        int $timeBudgetSeconds = 3600,
        int $maxIterations = 50,
        int $iterationBudgetSeconds = 60,
        float $complexityPenalty = 0.01,
        float $improvementThreshold = 0.0,
        array $settings = [],
    ): SkillBenchmark {
        // Resolve current best version for baseline measurement
        $currentVersion = SkillVersion::where('skill_id', $skill->id)
            ->orderByDesc('version')
            ->first();

        // Run baseline execution to record baseline_value
        $baselineValue = null;
        $firstInput = is_array($testInputs) && ! empty($testInputs) ? $testInputs[0] : [];

        try {
            $result = $this->executeSkill->execute(
                skill: $skill,
                input: is_array($firstInput) ? $firstInput : [],
                teamId: $skill->team_id,
                userId: $userId,
                purpose: 'benchmark_baseline',
            );

            /** @var SkillExecution $execution */
            $execution = $result['execution'];
            $baselineValue = $this->measureMetric->execute($execution, $metricName);
        } catch (\Throwable) {
            // Baseline measurement failure is non-fatal — loop will still run
        }

        $benchmark = DB::transaction(function () use (
            $skill, $metricName, $testInputs, $metricDirection,
            $timeBudgetSeconds, $maxIterations, $iterationBudgetSeconds,
            $complexityPenalty, $improvementThreshold, $settings,
            $baselineValue, $currentVersion
        ) {
            // Guard inside transaction with lock to prevent concurrent starts (TOCTOU)
            $alreadyRunning = SkillBenchmark::where('skill_id', $skill->id)
                ->where('status', BenchmarkStatus::Running)
                ->lockForUpdate()
                ->exists();

            if ($alreadyRunning) {
                throw new BenchmarkAlreadyRunningException(
                    "Skill {$skill->id} already has a running benchmark.",
                );
            }

            $benchmark = SkillBenchmark::create([
                'skill_id' => $skill->id,
                'team_id' => $skill->team_id,
                'best_version_id' => $currentVersion?->id,
                'metric_name' => $metricName,
                'metric_direction' => $metricDirection,
                'baseline_value' => $baselineValue,
                'best_value' => $baselineValue,
                'test_inputs' => $testInputs,
                'max_iterations' => $maxIterations,
                'time_budget_seconds' => $timeBudgetSeconds,
                'iteration_budget_seconds' => $iterationBudgetSeconds,
                'complexity_penalty' => $complexityPenalty,
                'improvement_threshold' => $improvementThreshold,
                'status' => BenchmarkStatus::Running,
                'started_at' => now(),
                'settings' => $settings,
            ]);

            return $benchmark;
        });

        // Dispatch first iteration
        SkillImprovementIterationJob::dispatch($benchmark->id, $userId);

        return $benchmark;
    }
}
