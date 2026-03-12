<?php

namespace App\Mcp\Tools\Integration;

use App\Domain\Integration\Actions\ExecuteIntegrationActionAction;
use App\Domain\Integration\Models\Integration;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class IntegrationExecuteTool extends Tool
{
    protected string $name = 'integration_execute';

    protected string $description = 'Execute an action on a connected integration, e.g. create_issue on GitHub, send_message on Slack.';

    public function __construct(private readonly ExecuteIntegrationActionAction $action) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'integration_id' => $schema->string()
                ->description('Integration UUID')
                ->required(),
            'action' => $schema->string()
                ->description('Driver action key, e.g. create_issue, send_message, create_record')
                ->required(),
            'params' => $schema->object()
                ->description('Action parameters (driver-specific)'),
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

        $actionKey = $request->get('action');

        if (! $actionKey) {
            return Response::error('action is required.');
        }

        try {
            $result = $this->action->execute(
                integration: $integration,
                action: $actionKey,
                params: (array) ($request->get('params') ?? []),
            );

            return Response::text(json_encode(['success' => true, 'result' => $result]));
        } catch (\Throwable $e) {
            return Response::error('Execute failed: '.$e->getMessage());
        }
    }
}
