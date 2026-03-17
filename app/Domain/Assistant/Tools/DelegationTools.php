<?php

namespace App\Domain\Assistant\Tools;

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
        ];
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
}
