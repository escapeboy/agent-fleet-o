<?php

namespace App\Domain\Assistant\Tools;

use App\Domain\Assistant\Services\RequestRouter;
use App\Domain\Crew\Actions\GenerateCrewFromPromptAction;
use App\Domain\Project\Actions\TriggerProjectRunAction;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectRun;
use Prism\Prism\Facades\Tool as PrismTool;
use Prism\Prism\Tool as PrismToolObject;

class DelegationTools
{
    /**
     * @return array<PrismToolObject>
     */
    public static function tools(string $conversationId): array
    {
        return [
            self::delegateAndNotify($conversationId),
            self::getDelegationResults(),
            self::designCrew(),
            self::routeRequest($conversationId),
        ];
    }

    private static function routeRequest(string $conversationId): PrismToolObject
    {
        return PrismTool::as('route_request')
            ->for('Front-door router: given a free-text request, rank the team\'s active agents, crews, and projects by fit and return the best handler(s) with match rationale — so you can point one ask at whoever should handle it without knowing the whole fleet. Set dispatch=true to immediately start the top candidate when it is a project.')
            ->withStringParameter('request', 'The task or question to route, in plain language.', required: true)
            ->withBooleanParameter('dispatch', 'If true and the top candidate is a project, trigger it immediately (fire-and-forget). Default false = recommend only.')
            ->using(function (string $request, ?bool $dispatch = false) use ($conversationId) {
                try {
                    $teamId = auth()->user()?->current_team_id;
                    if (! $teamId) {
                        return json_encode(['error' => 'No current team.']);
                    }

                    $ranked = app(RequestRouter::class)->route($teamId, $request);
                    if ($ranked === []) {
                        return json_encode([
                            'matches' => [],
                            'message' => 'No active agent, crew, or project matched this request.',
                        ]);
                    }

                    $top = $ranked[0];

                    if ($dispatch && $top['kind'] === 'project') {
                        $project = Project::where('id', $top['id'])->first();
                        if ($project) {
                            $run = app(TriggerProjectRunAction::class)->execute(
                                project: $project,
                                trigger: 'assistant',
                            );
                            $run->update(['triggered_by_conversation_id' => $conversationId]);

                            return json_encode([
                                'dispatched' => true,
                                'run_id' => $run->id,
                                'routed_to' => $top,
                                'message' => "Routed to project '{$project->title}' and started it.",
                            ]);
                        }
                    }

                    return json_encode([
                        'matches' => $ranked,
                        'recommended' => $top,
                        'message' => "Best fit: {$top['name']} ({$top['kind']}). Use delegate_and_notify to run a project, or create/execute a crew as appropriate.",
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    private static function delegateAndNotify(string $conversationId): PrismToolObject
    {
        return PrismTool::as('delegate_and_notify')
            ->for('Fire-and-forget: trigger a project run and notify you when the agents finish. Returns immediately without waiting for results.')
            ->withStringParameter('project_id', 'UUID of the project to run', required: true)
            ->withStringParameter('note', 'Optional note to log with this delegation (why you are delegating this)')
            ->withStringParameter('input_data_json', 'Optional JSON string of input_data to pass to the project run (e.g. {"topic": "AI trends"})')
            ->using(function (string $project_id, ?string $note = null, ?string $input_data_json = null) use ($conversationId) {
                try {
                    $project = Project::where('id', $project_id)->first();
                    if (! $project) {
                        return json_encode(['error' => "Project {$project_id} not found."]);
                    }

                    $inputData = null;
                    if ($input_data_json) {
                        $inputData = json_decode($input_data_json, true);
                    }

                    if ($note) {
                        $inputData = array_merge($inputData ?? [], ['_delegation_note' => $note]);
                    }

                    $run = app(TriggerProjectRunAction::class)->execute(
                        project: $project,
                        trigger: 'assistant',
                        inputData: $inputData,
                    );

                    // Attach conversation ID so we can notify when complete
                    $run->update(['triggered_by_conversation_id' => $conversationId]);

                    return json_encode([
                        'success' => true,
                        'run_id' => $run->id,
                        'project' => $project->title,
                        'message' => "Agents are working on it. I'll notify you when '{$project->title}' completes.",
                        'run_url' => route('projects.show', $project),
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    private static function getDelegationResults(): PrismToolObject
    {
        return PrismTool::as('get_delegation_results')
            ->for('Fetch the results of a previously delegated project run. Use the run_id returned by delegate_and_notify.')
            ->withStringParameter('run_id', 'UUID of the project run (from delegate_and_notify)', required: true)
            ->using(function (string $run_id) {
                /** @var ProjectRun|null $run */
                $run = ProjectRun::with(['project', 'experiment'])->find($run_id);

                if (! $run) {
                    return json_encode(['error' => "Run {$run_id} not found."]);
                }

                return json_encode([
                    'run_id' => $run->id,
                    'project' => $run->project?->title,
                    'status' => $run->status->value,
                    'trigger' => $run->trigger,
                    'started_at' => $run->started_at?->toIso8601String(),
                    'completed_at' => $run->completed_at?->toIso8601String(),
                    'duration' => $run->durationForHumans(),
                    'output_summary' => $run->output_summary,
                    'spend_credits' => $run->spend_credits,
                    'error_message' => $run->error_message,
                    'experiment_status' => $run->experiment?->status?->value,
                ]);
            });
    }

    private static function designCrew(): PrismToolObject
    {
        return PrismTool::as('design_crew')
            ->for('Design a crew of AI agents for a given goal. Returns a structured crew definition including coordinator, QA agent, worker roles, skills, and process type. Use create_crew to actually create it after reviewing the design.')
            ->withStringParameter('goal', 'What should this crew achieve? Describe the goal in plain language.', required: true)
            ->withStringParameter('team_id', 'Team ID (optional, uses current team if omitted)')
            ->using(function (string $goal, ?string $team_id = null) {
                try {
                    $teamId = $team_id ?? auth()->user()?->current_team_id;
                    $design = app(GenerateCrewFromPromptAction::class)->execute($goal, $teamId);

                    return json_encode([
                        'success' => true,
                        'design' => $design,
                        'next_step' => 'Review the design above. To create this crew, use the create_crew and create_agent tools with the suggested configuration.',
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }
}
