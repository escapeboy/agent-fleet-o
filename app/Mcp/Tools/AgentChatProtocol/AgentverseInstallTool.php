<?php

declare(strict_types=1);

namespace App\Mcp\Tools\AgentChatProtocol;

use App\Domain\AgentChatProtocol\Actions\InstallFromAgentverseAction;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

#[AssistantTool('write')]
class AgentverseInstallTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'agentverse_install';

    protected string $description = 'Install an Agentverse-hosted agent into this team as a callable ExternalAgent. Idempotent — returns existing record if the address was previously installed.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_address' => $schema->string()->description('The Agentverse bech32 address (e.g. agent1q...)')->required(),
            'use_proxy' => $schema->boolean()->description('Use proxy adapter instead of mailbox (default: false — mailbox)'),
        ];
    }

    public function handle(Request $request, InstallFromAgentverseAction $action): Response
    {
        $validated = $request->validate([
            'agent_address' => 'required|string|max:66',
            'use_proxy' => 'sometimes|boolean',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        try {
            $agent = $action->execute(
                teamId: (string) $teamId,
                agentAddress: $validated['agent_address'],
                useProxy: (bool) ($validated['use_proxy'] ?? false),
            );
        } catch (\Throwable $e) {
            return $this->failedPreconditionError($e->getMessage());
        }

        return Response::text(json_encode([
            'id' => $agent->id,
            'name' => $agent->name,
            'slug' => $agent->slug,
            'agent_address' => $agent->agent_address,
            'adapter_kind' => $agent->adapter_kind?->value,
            'status' => $agent->status->value,
        ]));
    }
}
