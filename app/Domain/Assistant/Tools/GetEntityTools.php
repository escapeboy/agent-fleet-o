<?php

namespace App\Domain\Assistant\Tools;

use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Models\Crew;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Project\Models\Project;
use App\Domain\Workflow\Models\Workflow;
use Prism\Prism\Facades\Tool as PrismTool;
use Prism\Prism\Tool as PrismToolObject;

class GetEntityTools
{
    /**
     * @return array<PrismToolObject>
     */
    public static function tools(): array
    {
        return [
            self::getExperiment(),
            self::getProject(),
            self::getAgent(),
            self::getCrew(),
            self::getWorkflow(),
        ];
    }

    private static function getExperiment(): PrismToolObject
    {
        return PrismTool::as('get_experiment')
            ->for('Get detailed information about a specific experiment')
            ->withStringParameter('experiment_id', 'The experiment UUID', required: true)
            ->using(function (string $experiment_id) {
                $exp = Experiment::with('stages')->find($experiment_id);
                if (! $exp) {
                    return json_encode(['error' => 'Experiment not found']);
                }

                return json_encode([
                    'id' => $exp->id,
                    'title' => $exp->title,
                    'thesis' => $exp->thesis,
                    'status' => $exp->status->value,
                    'track' => $exp->track->value,
                    'budget_spent' => $exp->budget_spent_credits,
                    'budget_cap' => $exp->budget_cap_credits,
                    'max_iterations' => $exp->max_iterations,
                    'current_iteration' => $exp->current_iteration,
                    'stages' => $exp->stages->map(fn ($s) => [
                        'type' => $s->type,
                        'status' => $s->status,
                        'output_preview' => mb_substr($s->output ?? '', 0, 200),
                    ])->toArray(),
                    'created' => $exp->created_at->toIso8601String(),
                    'url' => route('experiments.show', $exp),
                ]);
            });
    }

    private static function getProject(): PrismToolObject
    {
        return PrismTool::as('get_project')
            ->for('Get detailed information about a specific project')
            ->withStringParameter('project_id', 'The project UUID', required: true)
            ->using(function (string $project_id) {
                $project = Project::with('runs')->find($project_id);
                if (! $project) {
                    return json_encode(['error' => 'Project not found']);
                }

                return json_encode([
                    'id' => $project->id,
                    'title' => $project->title,
                    'type' => $project->type,
                    'status' => $project->status->value,
                    'description' => $project->description,
                    'goal' => $project->goal,
                    'recent_runs' => $project->runs->sortByDesc('created_at')->take(5)->map(fn ($r) => [
                        'id' => $r->id,
                        'status' => $r->status->value,
                        'created' => $r->created_at->diffForHumans(),
                    ])->values()->toArray(),
                    'created' => $project->created_at->toIso8601String(),
                    'url' => route('projects.show', $project),
                ]);
            });
    }

    private static function getAgent(): PrismToolObject
    {
        return PrismTool::as('get_agent')
            ->for('Get detailed information about a specific AI agent')
            ->withStringParameter('agent_id', 'The agent UUID', required: true)
            ->using(function (string $agent_id) {
                $agent = Agent::find($agent_id);
                if (! $agent) {
                    return json_encode(['error' => 'Agent not found']);
                }

                return json_encode([
                    'id' => $agent->id,
                    'name' => $agent->name,
                    'role' => $agent->role,
                    'goal' => $agent->goal,
                    'backstory' => $agent->backstory,
                    'provider' => $agent->provider,
                    'model' => $agent->model,
                    'status' => $agent->status->value,
                    'budget_spent' => $agent->budget_spent_credits,
                    'budget_cap' => $agent->budget_cap_credits,
                    'url' => route('agents.show', $agent),
                ]);
            });
    }

    private static function getCrew(): PrismToolObject
    {
        return PrismTool::as('get_crew')
            ->for('Get detailed information about a specific crew')
            ->withStringParameter('crew_id', 'The crew UUID', required: true)
            ->using(function (string $crew_id) {
                $crew = Crew::with('members.agent')->find($crew_id);
                if (! $crew) {
                    return json_encode(['error' => 'Crew not found']);
                }

                return json_encode([
                    'id' => $crew->id,
                    'name' => $crew->name,
                    'status' => $crew->status->value,
                    'process_type' => $crew->process_type->value,
                    'members' => $crew->members->map(fn ($m) => [
                        'role' => $m->role->value,
                        'agent_name' => $m->agent?->name,
                    ])->toArray(),
                    'url' => route('crews.show', $crew),
                ]);
            });
    }

    private static function getWorkflow(): PrismToolObject
    {
        return PrismTool::as('get_workflow')
            ->for('Get detailed information about a specific workflow')
            ->withStringParameter('workflow_id', 'The workflow UUID', required: true)
            ->using(function (string $workflow_id) {
                $workflow = Workflow::with('nodes', 'edges')->find($workflow_id);
                if (! $workflow) {
                    return json_encode(['error' => 'Workflow not found']);
                }

                return json_encode([
                    'id' => $workflow->id,
                    'name' => $workflow->name,
                    'status' => $workflow->status->value,
                    'description' => $workflow->description,
                    'nodes' => $workflow->nodes->map(fn ($n) => [
                        'id' => $n->id,
                        'type' => $n->type->value,
                        'label' => $n->label,
                    ])->toArray(),
                    'edges_count' => $workflow->edges->count(),
                    'url' => route('workflows.show', $workflow),
                ]);
            });
    }
}
