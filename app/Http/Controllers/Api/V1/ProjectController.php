<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Project\Actions\ArchiveProjectAction;
use App\Domain\Project\Actions\CreateProjectAction;
use App\Domain\Project\Actions\PauseProjectAction;
use App\Domain\Project\Actions\RestartProjectAction;
use App\Domain\Project\Actions\ResumeProjectAction;
use App\Domain\Project\Actions\TriggerProjectRunAction;
use App\Domain\Project\Actions\UpdateProjectAction;
use App\Domain\Project\Enums\ProjectStatus;
use App\Domain\Project\Enums\ProjectType;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectRun;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ProjectResource;
use App\Http\Resources\Api\V1\ProjectRunResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rules\Enum;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class ProjectController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $projects = QueryBuilder::for(Project::class)
            ->allowedFilters([
                AllowedFilter::exact('status'),
                AllowedFilter::exact('type'),
                AllowedFilter::partial('title'),
            ])
            ->allowedSorts(['created_at', 'updated_at', 'title', 'status', 'last_run_at'])
            ->defaultSort('-created_at')
            ->with('schedule')
            ->cursorPaginate($request->input('per_page', 15));

        return ProjectResource::collection($projects);
    }

    public function show(Project $project): ProjectResource
    {
        return new ProjectResource($project->load(['schedule']));
    }

    public function store(Request $request, CreateProjectAction $action): JsonResponse
    {
        $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', new Enum(ProjectType::class)],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'goal' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'workflow_id' => ['sometimes', 'nullable', 'uuid', 'exists:workflows,id'],
            'crew_id' => ['sometimes', 'nullable', 'uuid', 'exists:crews,id'],
            'agent_config' => ['sometimes', 'array'],
            'budget_config' => ['sometimes', 'array'],
            'notification_config' => ['sometimes', 'array'],
            'delivery_config' => ['sometimes', 'nullable', 'array'],
            'settings' => ['sometimes', 'array'],
            'schedule' => ['sometimes', 'nullable', 'array'],
            'milestones' => ['sometimes', 'array'],
            'milestones.*.title' => ['required_with:milestones', 'string', 'max:255'],
            'dependencies' => ['sometimes', 'array'],
            'dependencies.*.depends_on_id' => ['required_with:dependencies', 'uuid', 'exists:projects,id'],
            'allowed_tool_ids' => ['sometimes', 'array'],
            'allowed_tool_ids.*' => ['uuid'],
            'allowed_credential_ids' => ['sometimes', 'array'],
            'allowed_credential_ids.*' => ['uuid'],
        ]);

        $project = $action->execute(
            userId: $request->user()->id,
            title: $request->title,
            type: $request->type,
            description: $request->input('description'),
            goal: $request->input('goal'),
            crewId: $request->input('crew_id'),
            workflowId: $request->input('workflow_id'),
            agentConfig: $request->input('agent_config', []),
            budgetConfig: $request->input('budget_config', []),
            notificationConfig: $request->input('notification_config', []),
            settings: $request->input('settings', []),
            schedule: $request->input('schedule'),
            milestones: $request->input('milestones', []),
            dependencies: $request->input('dependencies', []),
            teamId: $request->user()->current_team_id,
            deliveryConfig: $request->input('delivery_config'),
        );

        if ($request->has('allowed_tool_ids')) {
            $project->update(['allowed_tool_ids' => $request->input('allowed_tool_ids')]);
        }
        if ($request->has('allowed_credential_ids')) {
            $project->update(['allowed_credential_ids' => $request->input('allowed_credential_ids')]);
        }

        return (new ProjectResource($project->load('schedule')))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, Project $project, UpdateProjectAction $action): ProjectResource
    {
        $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'workflow_id' => ['sometimes', 'nullable', 'uuid', 'exists:workflows,id'],
            'agent_config' => ['sometimes', 'array'],
            'budget_config' => ['sometimes', 'array'],
            'notification_config' => ['sometimes', 'array'],
            'delivery_config' => ['sometimes', 'nullable', 'array'],
            'schedule' => ['sometimes', 'nullable', 'array'],
            'allowed_tool_ids' => ['sometimes', 'array'],
            'allowed_tool_ids.*' => ['uuid'],
            'allowed_credential_ids' => ['sometimes', 'array'],
            'allowed_credential_ids.*' => ['uuid'],
        ]);

        $project = $action->execute($project, $request->only([
            'title', 'description', 'workflow_id',
            'agent_config', 'budget_config', 'notification_config',
            'delivery_config', 'schedule',
        ]));

        if ($request->has('allowed_tool_ids')) {
            $project->update(['allowed_tool_ids' => $request->input('allowed_tool_ids')]);
        }
        if ($request->has('allowed_credential_ids')) {
            $project->update(['allowed_credential_ids' => $request->input('allowed_credential_ids')]);
        }

        return new ProjectResource($project->load('schedule'));
    }

    public function destroy(Project $project, ArchiveProjectAction $action): JsonResponse
    {
        $action->execute($project);

        return response()->json(['message' => 'Project archived.']);
    }

    public function activate(Request $request, Project $project, TriggerProjectRunAction $triggerAction): ProjectResource
    {
        if ($project->status !== ProjectStatus::Draft) {
            abort(422, 'Only draft projects can be activated.');
        }

        $project->update([
            'status' => ProjectStatus::Active,
            'started_at' => now(),
        ]);

        if ($project->type === ProjectType::OneShot) {
            $triggerAction->execute($project->fresh(), 'initial');
        } elseif ($project->schedule?->run_immediately) {
            $triggerAction->execute($project->fresh(), 'initial');
        }

        return new ProjectResource($project->fresh()->load('schedule'));
    }

    public function pause(Request $request, Project $project, PauseProjectAction $action): ProjectResource
    {
        $action->execute($project, $request->input('reason', 'Paused via API'));

        return new ProjectResource($project->fresh()->load('schedule'));
    }

    public function resume(Project $project, ResumeProjectAction $action): ProjectResource
    {
        $action->execute($project);

        return new ProjectResource($project->fresh()->load('schedule'));
    }

    public function restart(Project $project, RestartProjectAction $action): ProjectResource
    {
        $action->execute($project);

        return new ProjectResource($project->fresh()->load('schedule'));
    }

    public function triggerRun(Project $project, TriggerProjectRunAction $action): JsonResponse
    {
        $action->execute($project, 'api');

        return response()->json(['message' => 'Run triggered.'], 202);
    }

    public function runs(Request $request, Project $project): AnonymousResourceCollection
    {
        $runs = QueryBuilder::for(
            ProjectRun::query()->where('project_id', $project->id)
        )
            ->allowedFilters([
                AllowedFilter::exact('status'),
            ])
            ->allowedSorts(['created_at', 'run_number', 'status', 'spend_credits'])
            ->defaultSort('-run_number')
            ->cursorPaginate($request->input('per_page', 15));

        return ProjectRunResource::collection($runs);
    }
}
