<?php

namespace App\Domain\Assistant\Tools;

use App\Domain\Agent\Actions\CreateAgentAction;
use App\Domain\Approval\Actions\ApproveAction;
use App\Domain\Approval\Actions\RejectAction;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Experiment\Actions\KillExperimentAction;
use App\Domain\Experiment\Actions\PauseExperimentAction;
use App\Domain\Experiment\Actions\ResumeExperimentAction;
use App\Domain\Experiment\Actions\RetryExperimentAction;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Project\Actions\ArchiveProjectAction;
use App\Domain\Project\Actions\CreateProjectAction;
use App\Domain\Project\Actions\TriggerProjectRunAction;
use App\Domain\Project\Enums\ProjectType;
use App\Domain\Project\Models\Project;
use Prism\Prism\Facades\Tool as PrismTool;
use Prism\Prism\Tool as PrismToolObject;

class MutationTools
{
    /**
     * @return array<PrismToolObject>
     */
    public static function writeTools(): array
    {
        return [
            self::createProject(),
            self::createAgent(),
            self::pauseExperiment(),
            self::resumeExperiment(),
            self::retryExperiment(),
            self::triggerProjectRun(),
            self::approveRequest(),
            self::rejectRequest(),
        ];
    }

    /**
     * @return array<PrismToolObject>
     */
    public static function destructiveTools(): array
    {
        return [
            self::killExperiment(),
            self::archiveProject(),
        ];
    }

    private static function createProject(): PrismToolObject
    {
        return PrismTool::as('create_project')
            ->for('Create a new project in Agent Fleet')
            ->withStringParameter('title', 'Project title', required: true)
            ->withStringParameter('description', 'Project description')
            ->withStringParameter('type', 'Project type: one_shot or continuous (default: one_shot)')
            ->using(function (string $title, ?string $description = null, ?string $type = null) {
                try {
                    $project = app(CreateProjectAction::class)->execute(
                        userId: auth()->id(),
                        title: $title,
                        type: $type && ProjectType::tryFrom($type) ? $type : ProjectType::OneShot->value,
                        description: $description,
                        teamId: auth()->user()->current_team_id,
                    );

                    return json_encode([
                        'success' => true,
                        'project_id' => $project->id,
                        'title' => $project->title,
                        'status' => $project->status->value,
                        'url' => route('projects.show', $project),
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    private static function createAgent(): PrismToolObject
    {
        return PrismTool::as('create_agent')
            ->for('Create a new AI agent')
            ->withStringParameter('name', 'Agent name', required: true)
            ->withStringParameter('role', 'Agent role description')
            ->withStringParameter('goal', 'Agent goal')
            ->withStringParameter('backstory', 'Agent backstory')
            ->withStringParameter('provider', 'LLM provider (anthropic, openai, google). Default: anthropic')
            ->withStringParameter('model', 'LLM model name. Default: claude-sonnet-4-5')
            ->using(function (string $name, ?string $role = null, ?string $goal = null, ?string $backstory = null, ?string $provider = null, ?string $model = null) {
                try {
                    $agent = app(CreateAgentAction::class)->execute(
                        name: $name,
                        provider: $provider ?? 'anthropic',
                        model: $model ?? 'claude-sonnet-4-5',
                        role: $role,
                        goal: $goal,
                        backstory: $backstory,
                        teamId: auth()->user()->current_team_id,
                    );

                    return json_encode([
                        'success' => true,
                        'agent_id' => $agent->id,
                        'name' => $agent->name,
                        'status' => $agent->status->value,
                        'url' => route('agents.show', $agent),
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    private static function pauseExperiment(): PrismToolObject
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

    private static function resumeExperiment(): PrismToolObject
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

    private static function retryExperiment(): PrismToolObject
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

    private static function triggerProjectRun(): PrismToolObject
    {
        return PrismTool::as('trigger_project_run')
            ->for('Trigger a new run for a project')
            ->withStringParameter('project_id', 'The project UUID', required: true)
            ->using(function (string $project_id) {
                $project = Project::find($project_id);
                if (! $project) {
                    return json_encode(['error' => 'Project not found']);
                }

                try {
                    $run = app(TriggerProjectRunAction::class)->execute($project, 'assistant');

                    return json_encode([
                        'success' => true,
                        'run_id' => $run->id,
                        'message' => "Project run triggered for '{$project->title}'.",
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    private static function approveRequest(): PrismToolObject
    {
        return PrismTool::as('approve_request')
            ->for('Approve a pending approval request')
            ->withStringParameter('approval_id', 'The approval request UUID', required: true)
            ->withStringParameter('notes', 'Optional approval notes')
            ->using(function (string $approval_id, ?string $notes = null) {
                $approval = ApprovalRequest::find($approval_id);
                if (! $approval) {
                    return json_encode(['error' => 'Approval request not found']);
                }

                try {
                    app(ApproveAction::class)->execute($approval, auth()->id(), $notes);

                    return json_encode(['success' => true, 'message' => 'Request approved.']);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    private static function rejectRequest(): PrismToolObject
    {
        return PrismTool::as('reject_request')
            ->for('Reject a pending approval request')
            ->withStringParameter('approval_id', 'The approval request UUID', required: true)
            ->withStringParameter('reason', 'Reason for rejection', required: true)
            ->withStringParameter('notes', 'Optional rejection notes')
            ->using(function (string $approval_id, string $reason, ?string $notes = null) {
                $approval = ApprovalRequest::find($approval_id);
                if (! $approval) {
                    return json_encode(['error' => 'Approval request not found']);
                }

                try {
                    app(RejectAction::class)->execute($approval, auth()->id(), $reason, $notes);

                    return json_encode(['success' => true, 'message' => 'Request rejected.']);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    private static function killExperiment(): PrismToolObject
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

    private static function archiveProject(): PrismToolObject
    {
        return PrismTool::as('archive_project')
            ->for('Archive a project permanently. This is a destructive action.')
            ->withStringParameter('project_id', 'The project UUID', required: true)
            ->using(function (string $project_id) {
                $project = Project::find($project_id);
                if (! $project) {
                    return json_encode(['error' => 'Project not found']);
                }

                try {
                    app(ArchiveProjectAction::class)->execute($project);

                    return json_encode(['success' => true, 'message' => "Project '{$project->title}' archived."]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }
}
