<?php

namespace App\Domain\Skill\Actions;

use App\Domain\Skill\Enums\IterationOutcome;
use App\Domain\Skill\Exceptions\MetricExtractionException;
use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Models\SkillBenchmark;
use App\Domain\Skill\Models\SkillExecution;
use App\Domain\Skill\Models\SkillIterationLog;
use App\Domain\Skill\Models\SkillVersion;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Executes one iteration of the benchmark keep/discard loop:
 *  1. Generate a candidate version via LLM
 *  2. Execute the skill against benchmark test_inputs
 *  3. Measure the metric
 *  4. Compute complexity delta
 *  5. Keep or discard based on effective improvement
 *  6. Log the iteration
 *  7. Update benchmark counters
 */
class RunSkillIterationAction
{
    public function __construct(
        private readonly GenerateSkillVersionFromBenchmarkAction $generateVersion,
        private readonly ExecuteSkillAction $executeSkill,
        private readonly MeasureSkillMetricAction $measureMetric,
        private readonly ComputeComplexityDeltaAction $computeComplexity,
        private readonly UpdateSkillAction $updateSkill,
    ) {}

    /**
     * @param  Skill  $skill  The skill being benchmarked (freshly loaded)
     * @param  SkillBenchmark  $benchmark  The running benchmark
     * @param  string  $userId  User owning the benchmark run
     * @return SkillIterationLog The log entry for this iteration
     */
    public function execute(Skill $skill, SkillBenchmark $benchmark, string $userId): SkillIterationLog
    {
        $startTime = hrtime(true);
        $currentBestVersion = $this->resolveBaselineVersion($skill, $benchmark);
        $baselineValue = (float) ($benchmark->best_value ?? $benchmark->baseline_value ?? 0.0);
        $iterationNumber = $benchmark->iteration_count + 1;

        $candidateVersion = null;
        $outcome = IterationOutcome::Crash;
        $metricValue = null;
        $complexityDelta = null;
        $effectiveImprovement = null;
        $diffSummary = null;
        $crashMessage = null;

        try {
            // 1. Generate candidate
            $candidateVersion = $this->generateVersion->execute($skill, $benchmark, $currentBestVersion, $userId);

            // Compute diff summary (first 500 chars of new template)
            /** @var array<string, mixed> $candidateConfig */
            $candidateConfig = $candidateVersion->configuration ?? [];
            $newTemplate = (string) ($candidateConfig['prompt_template'] ?? '');
            $diffSummary = mb_substr($newTemplate, 0, 500);

            // 2. Execute skill against first test input (or all, configurable)
            $testInputs = $benchmark->test_inputs;
            $firstInput = is_array($testInputs) && ! empty($testInputs) ? $testInputs[0] : [];

            $result = $this->executeSkill->execute(
                skill: $skill,
                input: is_array($firstInput) ? $firstInput : [],
                teamId: $benchmark->team_id,
                userId: $userId,
                purpose: 'benchmark',
            );

            /** @var SkillExecution $execution */
            $execution = $result['execution'];

            // 3. Measure metric
            $metricValue = $this->measureMetric->execute($execution, $benchmark->metric_name);

            // 4. Compute complexity delta
            $complexityDelta = $this->computeComplexity->execute($candidateVersion, $currentBestVersion);

            // 5. Determine effective improvement
            $metricDelta = $benchmark->metric_direction === 'minimize'
                ? $baselineValue - $metricValue   // lower is better
                : $metricValue - $baselineValue;  // higher is better

            $penaltyAmount = $benchmark->complexity_penalty * max(0, $complexityDelta);
            $effectiveImprovement = $metricDelta - $penaltyAmount;

            // 6. Keep or discard
            if ($effectiveImprovement > $benchmark->improvement_threshold) {
                $outcome = IterationOutcome::Keep;
            } else {
                $outcome = IterationOutcome::Discard;
            }
        } catch (MetricExtractionException $e) {
            $crashMessage = 'Metric extraction failed: '.$e->getMessage();
            $outcome = IterationOutcome::Crash;
        } catch (Throwable $e) {
            $crashMessage = $e->getMessage();
            $outcome = IterationOutcome::Crash;
        }

        $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

        // 7. Wrap keep/discard + log in a transaction
        $log = DB::transaction(function () use (
            $skill, $benchmark, $candidateVersion,
            $iterationNumber, $metricValue, $baselineValue, $complexityDelta,
            $effectiveImprovement, $outcome, $diffSummary, $crashMessage, $durationMs
        ) {
            if ($outcome === IterationOutcome::Keep) {
                // Advance best_version_id and best_value
                $benchmark->update([
                    'best_version_id' => $candidateVersion->id,
                    'best_value' => $metricValue,
                ]);
            } elseif ($outcome === IterationOutcome::Discard && $candidateVersion !== null) {
                // Mark the discarded version so it doesn't pollute version history.
                // Do NOT revert skill.current_version — the version row already exists
                // and best_version_id on the benchmark is the canonical "best" pointer.
                $candidateVersion->update(['status' => 'discarded']);
            }

            // Increment iteration count
            $benchmark->increment('iteration_count');

            return SkillIterationLog::create([
                'benchmark_id' => $benchmark->id,
                'skill_id' => $skill->id,
                'team_id' => $benchmark->team_id,
                'version_id' => $candidateVersion?->id,
                'iteration_number' => $iterationNumber,
                'metric_value' => $metricValue,
                'baseline_at_iteration' => $baselineValue,
                'complexity_delta' => $complexityDelta,
                'effective_improvement' => $effectiveImprovement,
                'outcome' => $outcome,
                'diff_summary' => $diffSummary,
                'crash_message' => $crashMessage,
                'duration_ms' => $durationMs,
            ]);
        });

        return $log;
    }

    private function resolveBaselineVersion(Skill $skill, SkillBenchmark $benchmark): SkillVersion
    {
        if ($benchmark->best_version_id) {
            $version = SkillVersion::find($benchmark->best_version_id);
            if ($version) {
                return $version;
            }
        }

        $version = SkillVersion::where('skill_id', $skill->id)
            ->orderByDesc('version')
            ->first();

        if (! $version) {
            throw new \RuntimeException('No SkillVersion found for skill '.$skill->id.' — cannot run benchmark.');
        }

        return $version;
    }
}
