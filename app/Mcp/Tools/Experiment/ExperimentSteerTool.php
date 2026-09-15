<?php

namespace App\Mcp\Tools\Experiment;

use App\Domain\Experiment\Actions\SteerExperimentAction;
use App\Domain\Experiment\Models\Experiment;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class ExperimentSteerTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'experiment_steer';

    protected string $description = 'Queue a steering message for a running experiment. Messages accumulate (up to 10 pending) and are all prepended, in order, to the system prompt of the next LLM call, then removed. Queued means durable, not yet applied — the audit log records both. Useful for mid-run corrections like "use staging DB, not prod".';

    public function schema(JsonSchema $schema): array
    {
        return [
            'experiment_id' => $schema->string()
                ->description('The experiment UUID')
                ->required(),
            'message' => $schema->string()
                ->description('The steering instruction to inject (max 2000 chars). Appended after any previously queued messages.')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'experiment_id' => 'required|string',
            'message' => 'required|string|min:1|max:2000',
        ]);

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

        try {
            $result = app(SteerExperimentAction::class)->execute(
                experiment: $experiment,
                message: $validated['message'],
                userId: auth()->id(),
            );

            $queue = SteerExperimentAction::pendingQueue($result->orchestration_config ?? []);
            $last = $queue[array_key_last($queue)] ?? null;

            return Response::text(json_encode([
                'success' => true,
                'experiment_id' => $result->id,
                'steering_id' => $last['id'] ?? null,
                'queued_at' => $last['queued_at'] ?? null,
                'queue_length' => count($queue),
            ]));
        } catch (\Throwable $e) {
            throw $e;
        }
    }
}
