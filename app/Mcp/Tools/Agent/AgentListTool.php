<?php

namespace App\Mcp\Tools\Agent;

use App\Domain\Agent\Models\Agent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class AgentListTool extends Tool
{
    protected string $name = 'agent_list';

    protected string $description = 'List AI agents with optional status filter. Returns id, name, role, provider, model, status.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->description('Filter by status: active, disabled')
                ->enum(['active', 'disabled']),
            'limit' => $schema->integer()
                ->description('Max results to return (default 10, max 100)')
                ->default(10),
        ];
    }

    public function handle(Request $request): Response
    {
        $query = Agent::query()->orderBy('name');

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        $limit = min((int) ($request->get('limit', 10)), 100);

        $agents = $query->limit($limit)->get(['id', 'name', 'role', 'provider', 'model', 'status']);

        return Response::text(json_encode([
            'count' => $agents->count(),
            'agents' => $agents->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->name,
                'role' => $a->role,
                'provider' => $a->provider,
                'model' => $a->model,
                'status' => $a->status->value,
            ])->toArray(),
        ]));
    }
}
