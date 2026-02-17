<?php

namespace App\Domain\Testing\Actions;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Enums\StageStatus;
use App\Domain\Experiment\Enums\StageType;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectRun;
use App\Domain\Testing\Enums\TestStatus;
use App\Domain\Testing\Enums\TestStrategy;
use App\Domain\Testing\Models\TestRun;
use App\Domain\Testing\Models\TestSuite;
use Illuminate\Database\Eloquent\Collection;

class RunRegressionTestsAction
{
    public function __construct(
        private EvaluateOutputAction $evaluateOutput,
    ) {}

    public function execute(Experiment $experiment): ?TestRun
    {
        $projectRun = ProjectRun::where('experiment_id', $experiment->id)->first();
        /** @var Project|null $project */
        $project = $projectRun?->project;
        if (! $project) {
            return null;
        }

        $suite = TestSuite::where('project_id', $project->id)
            ->where('is_active', true)
            ->first();

        if (! $suite) {
            return null;
        }

        // Skip if YOLO mode and strategy isn't lint_only
        /** @var TestStrategy $testStrategy */
        $testStrategy = $suite->test_strategy;
        if ($experiment->isYoloMode() && $testStrategy->value !== 'lint_only') {
            return null;
        }

        $testRun = TestRun::create([
            'test_suite_id' => $suite->id,
            'experiment_id' => $experiment->id,
            'status' => TestStatus::Running,
            'started_at' => now(),
        ]);

        // Collect experiment output for evaluation
        $outputData = $this->collectOutput($experiment);

        // Run evaluation
        $evaluation = $this->evaluateOutput->execute(
            output: $outputData,
            assertionRules: $suite->assertion_rules ?? [],
            qualityThreshold: $suite->quality_threshold ?? 0.7,
        );

        $status = $evaluation['passed'] ? TestStatus::Passed : TestStatus::Failed;

        $testRun->recordResult(
            status: $status,
            results: $evaluation['details'],
            score: $evaluation['score'],
            feedback: $evaluation['feedback'],
        );

        // Update suite stats
        $this->updateSuiteStats($suite);

        return $testRun;
    }

    private function collectOutput(Experiment $experiment): array
    {
        /** @var Collection<int, ExperimentStage> $stages */
        $stages = $experiment->stages()->orderBy('created_at')->get();

        /** @var ExperimentStatus $status */
        $status = $experiment->status;

        return [
            'experiment_id' => $experiment->id,
            'title' => $experiment->title,
            'status' => $status->value,
            'stages' => $stages->map(function (ExperimentStage $s): array {
                /** @var StageType $stage */
                $stage = $s->stage;
                /** @var StageStatus $stageStatus */
                $stageStatus = $s->status;

                return [
                    'stage' => $stage->value,
                    'status' => $stageStatus->value,
                    'output_snapshot' => $s->output_snapshot,
                ];
            })->toArray(),
        ];
    }

    private function updateSuiteStats(TestSuite $suite): void
    {
        $recent = $suite->testRuns()->latest()->take(20)->get();
        $passed = $recent->where('status', TestStatus::Passed)->count();
        $total = $recent->count();

        $suite->update([
            'pass_rate' => $total > 0 ? round($passed / $total, 2) : null,
            'last_run_at' => now(),
        ]);
    }
}
