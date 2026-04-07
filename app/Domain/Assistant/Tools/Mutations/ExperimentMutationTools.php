<?php

namespace App\Domain\Assistant\Tools\Mutations;

use App\Domain\Experiment\Actions\CreateExperimentAction;
use App\Domain\Experiment\Actions\KillExperimentAction;
use App\Domain\Experiment\Actions\PauseExperimentAction;
use App\Domain\Experiment\Actions\ResumeExperimentAction;
use App\Domain\Experiment\Actions\RetryExperimentAction;
use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use Prism\Prism\Facades\Tool as PrismTool;
use Prism\Prism\Tool as PrismToolObject;

final class ExperimentMutationTools
{
    /**
     * @return array<PrismToolObject>
     */
    public static function writeTools(): array
    {
        return [
            self::createExperiment(),
            self::startExperiment(),
            self::pauseExperiment(),
            self::resumeExperiment(),
            self::retryExperiment(),
        ];
    }

    /**
     * @return array<PrismToolObject>
     */
    public static function destructiveTools(): array
    {
        return [
            self::killExperiment(),
        ];
    }

    public static function createExperiment(): PrismToolObject
    {
        return PrismTool::as('create_experiment')
            ->for('Create a new experiment. Track must be one of: growth, retention, revenue, engagement, debug.')
            ->withStringParameter('title', 'Experiment title', required: true)
            ->withStringParameter('thesis', 'Experiment hypothesis or objective (default: "To be defined")')
            ->withStringParameter('track', 'Experiment track: growth, retention, revenue, engagement, debug (default: growth)')
            ->withStringParameter('budget_cap_credits', 'Budget cap in credits (default: 10000)')
            ->withStringParameter('workflow_id', 'Optional workflow UUID to materialize into the experiment')
            ->using(function (string $title, ?string $thesis = null, ?string $track = null, ?string $budget_cap_credits = null, ?string $workflow_id = null) {
                try {
                    $experiment = app(CreateExperimentAction::class)->execute(
                        userId: auth()->id(),
                        title: $title,
                        thesis: $thesis ?? 'To be defined',
                        track: $track ?? 'growth',
                        budgetCapCredits: $budget_cap_credits ? (int) $budget_cap_credits : 10000,
                        teamId: auth()->user()->current_team_id,
                        workflowId: $workflow_id,
                    );

                    return json_encode([
                        'success' => true,
                        'experiment_id' => $experiment->id,
                        'title' => $experiment->title,
                        'status' => $experiment->status->value,
                        'url' => route('experiments.show', $experiment),
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    public static function startExperiment(): PrismToolObject
    {
        return PrismTool::as('start_experiment')
            ->for('Start a draft experiment, kicking off the AI pipeline (scoring → planning → building → executing). The experiment must be in draft status.')
            ->withStringParameter('experiment_id', 'The experiment UUID', required: true)
            ->using(function (string $experiment_id) {
                $experiment = Experiment::find($experiment_id);
                if (! $experiment) {
                    return json_encode(['error' => 'Experiment not found']);
                }

                if ($experiment->status !== ExperimentStatus::Draft) {
                    return json_encode(['error' => "Cannot start experiment in '{$experiment->status->value}' status. Only draft experiments can be started."]);
                }

                try {
                    $result = app(TransitionExperimentAction::class)->execute(
                        experiment: $experiment,
                        toState: ExperimentStatus::Scoring,
                        reason: 'Started via assistant',
                        actorId: auth()->id(),
                    );

                    return json_encode([
                        'success' => true,
                        'experiment_id' => $result->id,
                        'title' => $result->title,
                        'status' => $result->status->value,
                        'message' => "Experiment '{$result->title}' is now running.",
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    public static function pauseExperiment(): PrismToolObject
    {
        return PrismTool::as('pause_experiment')
            ->for('Pause a running experiment')
            ->withStringParameter('experiment_id', 'The experiment UUID', required: true)
            ->using(function (string $experiment_id) {
                $experiment = Experiment::find($experiment_id);
                if (! $experiment) {
                    return json_encode(['error' => 'Experiment not found']);
                }

                try {
                    app(PauseExperimentAction::class)->execute($experiment, auth()->id());

                    return json_encode(['success' => true, 'message' => "Experiment '{$experiment->title}' paused."]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    public static function resumeExperiment(): PrismToolObject
    {
        return PrismTool::as('resume_experiment')
            ->for('Resume a paused experiment')
            ->withStringParameter('experiment_id', 'The experiment UUID', required: true)
            ->using(function (string $experiment_id) {
                $experiment = Experiment::find($experiment_id);
                if (! $experiment) {
                    return json_encode(['error' => 'Experiment not found']);
                }

                try {
                    app(ResumeExperimentAction::class)->execute($experiment, auth()->id());

                    return json_encode(['success' => true, 'message' => "Experiment '{$experiment->title}' resumed."]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    public static function retryExperiment(): PrismToolObject
    {
        return PrismTool::as('retry_experiment')
            ->for('Retry a failed experiment')
            ->withStringParameter('experiment_id', 'The experiment UUID', required: true)
            ->using(function (string $experiment_id) {
                $experiment = Experiment::find($experiment_id);
                if (! $experiment) {
                    return json_encode(['error' => 'Experiment not found']);
                }

                try {
                    app(RetryExperimentAction::class)->execute($experiment, auth()->id());

                    return json_encode(['success' => true, 'message' => "Experiment '{$experiment->title}' retrying."]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    public static function killExperiment(): PrismToolObject
    {
        return PrismTool::as('kill_experiment')
            ->for('Kill/terminate an experiment permanently. This is a destructive action.')
            ->withStringParameter('experiment_id', 'The experiment UUID', required: true)
            ->withStringParameter('reason', 'Reason for killing the experiment')
            ->using(function (string $experiment_id, ?string $reason = null) {
                $experiment = Experiment::find($experiment_id);
                if (! $experiment) {
                    return json_encode(['error' => 'Experiment not found']);
                }

                try {
                    app(KillExperimentAction::class)->execute($experiment, auth()->id(), $reason ?? 'Killed via assistant');

                    return json_encode(['success' => true, 'message' => "Experiment '{$experiment->title}' killed."]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }
}
