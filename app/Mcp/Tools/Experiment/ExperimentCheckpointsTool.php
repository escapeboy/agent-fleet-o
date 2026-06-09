<?php

namespace App\Mcp\Tools\Experiment;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class ExperimentCheckpointsTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'experiment_checkpoints';

    protected string $description = 'List an experiment\'s checkpoints — playbook steps carrying checkpoint_data, most-recently-updated first. Returns step id, status, checkpoint version, worker_id, idempotency_key, and last heartbeat.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'experiment_id' => $schema->string()
                ->description('The experiment UUID')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['experiment_id' => 'required|string']);

        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null) ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $experiment = Experiment::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($validated['experiment_id']);

        if (! $experiment) {
            return $this->notFoundError('experiment');
        }

        $checkpoints = PlaybookStep::where('experiment_id', $experiment->id)
            ->whereNotNull('checkpoint_data')
            ->orderByDesc('updated_at')
            ->get();

        return Response::text(json_encode([
            'experiment_id' => $experiment->id,
            'count' => $checkpoints->count(),
            'checkpoints' => $checkpoints->map(fn (PlaybookStep $s) => [
                'id' => $s->id,
                'order' => $s->order,
                'status' => $s->status,
                'version' => $s->checkpoint_version,
                'worker_id' => $s->worker_id,
                'idempotency_key' => $s->idempotency_key,
                'last_heartbeat' => $s->last_heartbeat_at?->toIso8601String(),
                'updated_at' => $s->updated_at?->toIso8601String(),
            ])->toArray(),
        ]));
    }
}
