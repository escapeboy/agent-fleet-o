<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Project\Models\ProjectRun;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class GetDelegationResultsTool implements Tool
{
    public function name(): string
    {
        return 'get_delegation_results';
    }

    public function description(): string
    {
        return 'Fetch the results of a previously delegated project run. Use the run_id returned by delegate_and_notify.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'run_id' => $schema->string()->required()->description('UUID of the project run (from delegate_and_notify)'),
        ];
    }

    public function handle(Request $request): string
    {
        /** @var ProjectRun|null $run */
        $run = ProjectRun::with(['project', 'experiment'])->find($request->get('run_id'));

        if (! $run) {
            return json_encode(['error' => "Run {$request->get('run_id')} not found."]);
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
    }
}
