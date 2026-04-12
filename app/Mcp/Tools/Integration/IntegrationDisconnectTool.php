<?php

namespace App\Mcp\Tools\Integration;

use App\Domain\Integration\Actions\DisconnectIntegrationAction;
use App\Domain\Integration\Models\Integration;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('destructive')]
class IntegrationDisconnectTool extends Tool
{
    protected string $name = 'integration_disconnect';

    protected string $description = 'Disconnect and remove an integration, revoking stored credentials.';

    public function __construct(private readonly DisconnectIntegrationAction $action) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'integration_id' => $schema->string()
                ->description('Integration UUID')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? null;

        if (! $teamId) {
            return Response::error('No team context.');
        }

        $integration = Integration::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('id', $request->get('integration_id'))
            ->first();

        if (! $integration) {
            return Response::error('Integration not found.');
        }

        $this->action->execute($integration);

        return Response::text(json_encode(['success' => true, 'message' => 'Integration disconnected.']));
    }
}
