<?php

declare(strict_types=1);

namespace App\Mcp\Tools\AgentChatProtocol;

use App\Domain\AgentChatProtocol\Services\AgentverseClient;
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
class AgentverseSearchTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'agentverse_search';

    protected string $description = 'Search the Agentverse marketplace for specialist agents callable via the Agent Chat Protocol. Requires the team to have an Agentverse credential configured.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Search keywords (optional)'),
            'category' => $schema->string()->description('Category filter (optional)'),
            'limit' => $schema->integer()->description('Max results (default 25, max 50)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'query' => 'sometimes|string|max:200',
            'category' => 'sometimes|string|max:100',
            'limit' => 'sometimes|integer|min:1|max:50',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $client = AgentverseClient::forTeam((string) $teamId);
        if ($client === null) {
            return $this->failedPreconditionError('Team has no Agentverse credential configured. Add a Credential with metadata.provider = "agentverse" and secret_data.api_key.');
        }

        try {
            $agents = $client->listAgents(array_filter([
                'search' => $validated['query'] ?? null,
                'category' => $validated['category'] ?? null,
                'limit' => $validated['limit'] ?? 25,
            ]));
        } catch (\Throwable $e) {
            return $this->unavailableError('Agentverse listing failed: '.$e->getMessage());
        }

        return Response::text(json_encode([
            'agents' => $agents,
            'count' => count($agents),
        ]));
    }
}
