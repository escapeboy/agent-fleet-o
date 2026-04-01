<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Experiment\Models\Experiment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class ListExperimentsTool implements Tool
{
    public function name(): string
    {
        return 'list_experiments';
    }

    public function description(): string
    {
        return 'List experiments with optional status filter';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->description('Filter by status (e.g. draft, running, completed, failed, paused, killed)'),
            'limit' => $schema->integer()->description('Max results to return (default 10)'),
        ];
    }

    public function handle(Request $request): string
    {
        $query = Experiment::query()->orderByDesc('created_at');

        if ($request->get('status')) {
            $query->where('status', $request->get('status'));
        }

        $experiments = $query->limit($request->get('limit', 10))->get(['id', 'title', 'status', 'track', 'budget_spent_credits', 'budget_cap_credits', 'created_at']);

        return json_encode([
            'count' => $experiments->count(),
            'experiments' => $experiments->map(fn ($e) => [
                'id' => $e->id,
                'title' => $e->title,
                'status' => $e->status->value,
                'track' => $e->track->value,
                'budget' => "{$e->budget_spent_credits}/{$e->budget_cap_credits}",
                'created' => $e->created_at->diffForHumans(),
            ])->toArray(),
        ]);
    }
}
