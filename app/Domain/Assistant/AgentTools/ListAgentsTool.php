<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Agent\Models\Agent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class ListAgentsTool implements Tool
{
    public function name(): string
    {
        return 'list_agents';
    }

    public function description(): string
    {
        return 'List AI agents with optional status filter';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->description('Filter by status (e.g. active, disabled)'),
            'limit' => $schema->integer()->description('Max results to return (default 10)'),
        ];
    }

    public function handle(Request $request): string
    {
        $query = Agent::query()->orderBy('name');

        if ($request->get('status')) {
            $query->where('status', $request->get('status'));
        }

        $agents = $query->limit($request->get('limit', 10))->get(['id', 'name', 'role', 'provider', 'model', 'status']);

        return json_encode([
            'count' => $agents->count(),
            'agents' => $agents->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->name,
                'role' => $a->role,
                'provider' => $a->provider,
                'model' => $a->model,
                'status' => $a->status->value,
            ])->toArray(),
        ]);
    }
}
