<?php

namespace App\Domain\Assistant\Tools\Mutations;

use App\Domain\Project\Actions\ArchiveProjectAction;
use App\Domain\Project\Actions\CreateProjectAction;
use App\Domain\Project\Actions\PauseProjectAction;
use App\Domain\Project\Actions\ResumeProjectAction;
use App\Domain\Project\Actions\TriggerProjectRunAction;
use App\Domain\Project\Actions\UpdateProjectAction;
use App\Domain\Project\Enums\ProjectStatus;
use App\Domain\Project\Enums\ProjectType;
use App\Domain\Project\Models\Project;
use Illuminate\Support\Facades\DB;
use Prism\Prism\Facades\Tool as PrismTool;
use Prism\Prism\Tool as PrismToolObject;

final class ProjectMutationTools
{
    /**
     * @return array<PrismToolObject>
     */
    public static function writeTools(): array
    {
        return [
            self::createProject(),
            self::updateProject(),
            self::activateProject(),
            self::pauseProject(),
            self::resumeProject(),
            self::triggerProjectRun(),
        ];
    }

    /**
     * @return array<PrismToolObject>
     */
    public static function destructiveTools(): array
    {
        return [
            self::archiveProject(),
        ];
    }

    public static function createProject(): PrismToolObject
    {
        return PrismTool::as('create_project')
            ->for('Create a new project in FleetQ')
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

    public static function updateProject(): PrismToolObject
    {
        return PrismTool::as('update_project')
            ->for('Update an existing project title or description')
            ->withStringParameter('project_id', 'The project UUID', required: true)
            ->withStringParameter('title', 'New project title')
            ->withStringParameter('description', 'New project description')
            ->using(function (string $project_id, ?string $title = null, ?string $description = null) {
                $project = Project::find($project_id);
                if (! $project) {
                    return json_encode(['error' => 'Project not found']);
                }

                try {
                    $data = array_filter(['title' => $title, 'description' => $description], fn ($v) => $v !== null);

                    if (empty($data)) {
                        return json_encode(['error' => 'No attributes provided to update']);
                    }

                    $project = app(UpdateProjectAction::class)->execute($project, $data);

                    return json_encode([
                        'success' => true,
                        'project_id' => $project->id,
                        'title' => $project->title,
                        'status' => $project->status->value,
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    public static function activateProject(): PrismToolObject
    {
        return PrismTool::as('activate_project')
            ->for('Activate a draft or failed project so it can run. The project must be in draft or failed status.')
            ->withStringParameter('project_id', 'The project UUID', required: true)
            ->using(function (string $project_id) {
                $project = Project::find($project_id);
                if (! $project) {
                    return json_encode(['error' => 'Project not found']);
                }

                if (! $project->status->canTransitionTo(ProjectStatus::Active)) {
                    return json_encode(['error' => "Cannot activate project in '{$project->status->value}' status."]);
                }

                try {
                    DB::transaction(function () use ($project) {
                        $project->update(['status' => ProjectStatus::Active]);
                        if ($project->schedule) {
                            $project->schedule->update(['enabled' => true]);
                        }
                    });

                    $project->refresh();

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

    public static function pauseProject(): PrismToolObject
    {
        return PrismTool::as('pause_project')
            ->for('Pause an active project and its schedule')
            ->withStringParameter('project_id', 'The project UUID', required: true)
            ->withStringParameter('reason', 'Optional reason for pausing')
            ->using(function (string $project_id, ?string $reason = null) {
                $project = Project::find($project_id);
                if (! $project) {
                    return json_encode(['error' => 'Project not found']);
                }

                try {
                    app(PauseProjectAction::class)->execute($project, $reason);

                    return json_encode(['success' => true, 'message' => "Project '{$project->title}' paused."]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    public static function resumeProject(): PrismToolObject
    {
        return PrismTool::as('resume_project')
            ->for('Resume a paused project and re-enable its schedule')
            ->withStringParameter('project_id', 'The project UUID', required: true)
            ->using(function (string $project_id) {
                $project = Project::find($project_id);
                if (! $project) {
                    return json_encode(['error' => 'Project not found']);
                }

                try {
                    app(ResumeProjectAction::class)->execute($project);

                    return json_encode(['success' => true, 'message' => "Project '{$project->title}' resumed."]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    public static function triggerProjectRun(): PrismToolObject
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

    public static function archiveProject(): PrismToolObject
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
