<?php

namespace App\Domain\Experiment\States;

use App\Domain\Agent\Models\AgentExecution;
use App\Domain\Experiment\DTOs\DoneVerdict;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Enums\StageStatus;
use App\Domain\Experiment\Enums\StageType;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Services\DoneConditionJudge;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectRun;

class TransitionPrerequisiteValidator
{
    /**
     * Most-recent verdict from the Done-Condition Gate. The transition action
     * persists this onto the resulting ExperimentStateTransition row so audit
     * can review the judge's reasoning.
     */
    public ?DoneVerdict $lastJudgeVerdict = null;

    public function validate(Experiment $experiment, ExperimentStatus $toState): ?string
    {
        $this->lastJudgeVerdict = null;

        return match ($toState) {
            ExperimentStatus::Building => $this->validateBuildingPrereqs($experiment),
            ExperimentStatus::Executing => $this->validateExecutingPrereqs($experiment),
            ExperimentStatus::CollectingMetrics => $this->validateMetricsPrereqs($experiment),
            ExperimentStatus::Completed => $this->validateDoneCondition($experiment),
            default => null,
        };
    }

    private function validateBuildingPrereqs(Experiment $experiment): ?string
    {
        $planStage = $experiment->stages()
            ->where('stage', StageType::Planning)
            ->where('status', StageStatus::Completed)
            ->whereNotNull('output_snapshot')
            ->latest()
            ->first();

        if (! $planStage || empty($planStage->output_snapshot)) {
            return 'Cannot transition to Building: no completed plan exists.';
        }

        return null;
    }

    private function validateExecutingPrereqs(Experiment $experiment): ?string
    {
        $hasWorkflow = ! empty($experiment->constraints['workflow_graph']);

        if ($hasWorkflow && ! $experiment->playbookSteps()->exists()) {
            return 'Cannot transition to Executing: no playbook steps materialized from workflow.';
        }

        return null;
    }

    private function validateMetricsPrereqs(Experiment $experiment): ?string
    {
        if ($experiment->playbookSteps()->exists()) {
            $completed = $experiment->playbookSteps()->where('status', 'completed')->count();

            if ($completed === 0) {
                return 'Cannot transition to CollectingMetrics: no playbook steps completed.';
            }
        }

        return null;
    }

    /**
     * Done-Condition Gate (Anthropic-style anti-premature-stop judge).
     *
     * Off by default. Opt-in per Experiment via experiment.constraints:
     *   - done_gate_enabled = true      → gate runs when transitioning to Completed
     *   - done_gate_kill_switch = true  → bypass even when enabled
     *   - done_gate_judge = {provider, model}  → optional model override
     *
     * If a Project owns this experiment (via project_run_id when the column
     * exists), project.settings keys of the same names take precedence so
     * teams can flip the gate at the project level without touching every
     * experiment.
     */
    private function validateDoneCondition(Experiment $experiment): ?string
    {
        $settings = $this->resolveDoneGateSettings($experiment);
        $enabled = (bool) ($settings['done_gate_enabled'] ?? false);
        $killSwitch = (bool) ($settings['done_gate_kill_switch'] ?? false);

        if (! $enabled || $killSwitch) {
            return null;
        }

        $features = $this->resolveFeatures($experiment);
        if ($features === []) {
            // No externalized criteria → nothing to verify against; let it through.
            return null;
        }

        $evidence = $this->collectEvidence($experiment);
        $override = $settings['done_gate_judge'] ?? null;

        $judge = app(DoneConditionJudge::class);
        $verdict = $judge->evaluate(
            experiment: $experiment,
            features: $features,
            evidence: $evidence,
            override: is_array($override) ? $override : null,
        );
        $this->lastJudgeVerdict = $verdict;

        if (! $verdict->confirmed) {
            $missing = $verdict->missing !== []
                ? ' Missing: '.implode('; ', array_slice($verdict->missing, 0, 5))
                : '';

            return 'Done-Condition Gate denied transition to Completed: '.$verdict->reasoning.$missing;
        }

        return null;
    }

    private function resolveProject(Experiment $experiment): ?Project
    {
        $projectRunId = $experiment->project_run_id ?? null;
        if (! is_string($projectRunId) || $projectRunId === '') {
            return null;
        }
        $run = ProjectRun::withoutGlobalScopes()->find($projectRunId);
        if (! $run) {
            return null;
        }

        return Project::withoutGlobalScopes()->find($run->project_id);
    }

    /**
     * Merge experiment.constraints with the owning project.settings (when
     * present), letting project-level flags override experiment-level ones.
     *
     * @return array<string, mixed>
     */
    private function resolveDoneGateSettings(Experiment $experiment): array
    {
        $constraints = is_array($experiment->constraints ?? null) ? $experiment->constraints : [];
        $settings = [
            'done_gate_enabled' => $constraints['done_gate_enabled'] ?? false,
            'done_gate_kill_switch' => $constraints['done_gate_kill_switch'] ?? false,
            'done_gate_judge' => $constraints['done_gate_judge'] ?? null,
        ];

        $project = $this->resolveProject($experiment);
        if ($project) {
            $ps = is_array($project->settings ?? null) ? $project->settings : [];
            foreach (['done_gate_enabled', 'done_gate_kill_switch', 'done_gate_judge'] as $key) {
                if (array_key_exists($key, $ps)) {
                    $settings[$key] = $ps[$key];
                }
            }
        }

        return $settings;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function resolveFeatures(Experiment $experiment): array
    {
        $latest = AgentExecution::query()
            ->where('experiment_id', $experiment->id)
            ->whereNotNull('workspace_contract')
            ->latest('updated_at')
            ->first();

        if (! $latest) {
            return [];
        }

        $contract = $latest->workspace_contract;
        $jsonStr = is_array($contract) ? ($contract['feature_list_json'] ?? null) : null;
        if (! is_string($jsonStr) || $jsonStr === '') {
            return [];
        }

        $decoded = json_decode($jsonStr, true);

        return is_array($decoded) && is_array($decoded['features'] ?? null) ? $decoded['features'] : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function collectEvidence(Experiment $experiment): array
    {
        $stages = $experiment->stages()
            ->latest()
            ->take(5)
            ->get(['stage', 'status', 'output_snapshot'])
            ->map(fn ($s) => [
                'stage' => $s->stage instanceof \BackedEnum ? $s->stage->value : (string) $s->stage,
                'status' => $s->status instanceof \BackedEnum ? $s->status->value : (string) $s->status,
                'output_snapshot' => $s->output_snapshot,
            ])
            ->all();

        return [
            'experiment_id' => $experiment->id,
            'recent_stages' => $stages,
            'goal' => $experiment->goal ?? null,
        ];
    }
}
