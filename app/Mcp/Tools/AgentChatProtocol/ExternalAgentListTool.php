<?php

declare(strict_types=1);

namespace App\Mcp\Tools\AgentChatProtocol;

use App\Domain\AgentChatProtocol\Models\ExternalAgent;
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
class ExternalAgentListTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'external_agent_list';

    protected string $description = 'List remote agents registered via the Agent Chat Protocol for the current team. Includes status, endpoint, capabilities, and last call timestamps.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->description('Optional filter: active, paused, unreachable, disabled'),
            'limit' => $schema->integer()->description('Max results (default 25, max 100)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'status' => 'sometimes|string|in:active,paused,unreachable,disabled',
            'limit' => 'sometimes|integer|min:1|max:100',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $query = ExternalAgent::withoutGlobalScopes()->where('team_id', $teamId);
        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $agents = $query->orderByDesc('created_at')
            ->limit((int) ($validated['limit'] ?? 25))
            ->get();

        return Response::text(json_encode([
            'agents' => $agents->map(fn (ExternalAgent $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'slug' => $a->slug,
                'endpoint_url' => $a->endpoint_url,
                'status' => $a->status->value,
                'protocol_version' => $a->protocol_version,
                'capabilities' => $a->capabilities,
                'last_call_at' => $a->last_call_at?->toIso8601String(),
                'last_success_at' => $a->last_success_at?->toIso8601String(),
                'last_error' => $a->last_error,
            ])->toArray(),
            'count' => $agents->count(),
        ]));
    }
}
