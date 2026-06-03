<?php

declare(strict_types=1);

namespace App\Mcp\Tools\AgentChatProtocol;

use App\Domain\AgentChatProtocol\Actions\DiscoverA2aAgentAction;
use App\Domain\AgentChatProtocol\Enums\AdapterKind;
use App\Domain\AgentChatProtocol\Models\ExternalAgent;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class A2aDiscoverTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'external_agent_discover_a2a';

    protected string $description = 'Discover an external A2A (Agent-to-Agent) agent by fetching its AgentCard from the well-known URI (/.well-known/agent-card.json) and registering it as an ExternalAgent. Pass the agent domain/base URL or a full card URL. Discovery only — calling A2A agents is not yet supported. Requires A2A_DISCOVERY_ENABLED=true.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'url' => $schema->string()->description('The external agent domain/base URL (e.g. https://agent.example.com) or a full AgentCard URL.')->required(),
            'credential_id' => $schema->string()->description('Optional credential UUID to attach for authenticated calls.'),
        ];
    }

    public function handle(Request $request, DiscoverA2aAgentAction $action): Response
    {
        $validated = $request->validate([
            'url' => 'required|string|url',
            'credential_id' => 'nullable|string',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        try {
            /** @var ExternalAgent $agent */
            $agent = $action->execute(
                teamId: (string) $teamId,
                url: (string) $validated['url'],
                credentialId: isset($validated['credential_id']) ? (string) $validated['credential_id'] : null,
            );
        } catch (\Throwable $e) {
            return $this->unavailableError($e->getMessage());
        }

        $fetchedAt = $agent->manifest_fetched_at;

        return Response::text((string) json_encode([
            'id' => $agent->id,
            'name' => $agent->name,
            'slug' => $agent->slug,
            // DiscoverA2aAgentAction only ever produces A2A-adapter agents.
            'adapter_kind' => AdapterKind::A2a->value,
            'endpoint_url' => $agent->endpoint_url,
            'capabilities' => $agent->capabilities,
            'manifest_fetched_at' => $fetchedAt ? Carbon::parse((string) $fetchedAt)->toIso8601String() : null,
        ]));
    }
}
