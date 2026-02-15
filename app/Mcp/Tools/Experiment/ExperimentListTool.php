<?php

namespace App\Mcp\Tools\Experiment;

use App\Domain\Experiment\Models\Experiment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class ExperimentListTool extends Tool
{
    protected string $name = 'experiment_list';

    protected string $description = 'List experiments with optional status filter. Returns id, title, status, track, budget info, and created_at.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->description('Filter by status: draft, scoring, planning, building, executing, completed, killed, paused, etc.')
                ->enum([
                    'draft', 'signal_detected', 'scoring', 'scoring_failed',
                    'planning', 'planning_failed', 'building', 'building_failed',
                    'awaiting_approval', 'approved', 'rejected', 'executing',
                    'execution_failed', 'collecting_metrics', 'evaluating',
                    'iterating', 'paused', 'completed', 'killed', 'discarded', 'expired',
                ]),
            'limit' => $schema->integer()
                ->description('Max results to return (default 10, max 100)')
                ->default(10),
        ];
    }

    public function handle(Request $request): Response
    {
        $query = Experiment::query()->orderByDesc('created_at');

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        $limit = min((int) ($request->get('limit', 10)), 100);

        $experiments = $query->limit($limit)->get();

        return Response::text(json_encode([
            'count' => $experiments->count(),
            'experiments' => $experiments->map(fn ($e) => [
                'id' => $e->id,
                'title' => $e->title,
                'status' => $e->status->value,
                'track' => $e->track->value,
                'budget_spent_credits' => $e->budget_spent_credits,
                'budget_cap_credits' => $e->budget_cap_credits,
                'created_at' => $e->created_at?->toIso8601String(),
            ])->toArray(),
        ]));
    }
}
