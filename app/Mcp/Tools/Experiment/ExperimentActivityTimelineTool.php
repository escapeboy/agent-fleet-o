<?php

namespace App\Mcp\Tools\Experiment;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Services\UnifiedTimelineService;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class ExperimentActivityTimelineTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'experiment_activity_timeline';

    protected string $description = 'Get the unified activity timeline for an experiment — state transitions, AI runs, approvals and sandbox files merged chronologically, newest first.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'experiment_id' => $schema->string()
                ->description('The experiment UUID')
                ->required(),
            'limit' => $schema->integer()
                ->description('Maximum entries to return (default: 100, max: 500)'),
            'kind' => $schema->string()
                ->description('Restrict to one kind: transition, ai_run, approval, or sandbox_file'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null) ?? auth()->user()?->current_team_id;

        if (! $teamId) {
            return $this->permissionDeniedError('No team context.');
        }

        $validated = $request->validate([
            'experiment_id' => 'required|string',
            'limit' => 'integer|min:1|max:500',
            'kind' => 'string|in:'.implode(',', UnifiedTimelineService::KINDS),
        ]);

        $experiment = Experiment::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($validated['experiment_id']);

        if (! $experiment) {
            return $this->notFoundError('experiment');
        }

        $entries = app(UnifiedTimelineService::class)->build(
            experiment: $experiment,
            limit: $validated['limit'] ?? 100,
            kind: $validated['kind'] ?? null,
        );

        return Response::text(json_encode([
            'experiment_id' => $experiment->id,
            'count' => $entries->count(),
            'entries' => $entries->map(fn ($e) => $e->toArray())->values()->toArray(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
