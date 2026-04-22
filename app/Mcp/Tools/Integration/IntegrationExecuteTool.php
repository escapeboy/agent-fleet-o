<?php

namespace App\Mcp\Tools\Integration;

use App\Domain\Integration\Actions\ExecuteIntegrationActionAction;
use App\Domain\Integration\Models\Integration;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class IntegrationExecuteTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'integration_execute';

    protected string $description = 'Execute an action on a connected integration, e.g. create_issue on GitHub, send_message on Slack.';

    public function __construct(private readonly ExecuteIntegrationActionAction $action) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'integration_id' => $schema->string()
                ->description('Integration UUID')
                ->required(),
            'integration_action' => $schema->string()
                ->description('Driver action key, e.g. post_tweet, create_issue, send_message')
                ->required(),
            'params' => $schema->object()
                ->description('Action parameters (driver-specific)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? null;

        if (! $teamId) {
            return $this->permissionDeniedError('No team context.');
        }

        $integration = Integration::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('id', $request->get('integration_id'))
            ->first();

        if (! $integration) {
            return $this->notFoundError('integration');
        }

        // Use integration_action (not action, which is consumed by the CompactTool dispatcher)
        $actionKey = $request->get('integration_action') ?? $request->get('action');

        if (! $actionKey || $actionKey === 'execute') {
            return $this->invalidArgumentError('integration_action is required (e.g. post_tweet, create_issue).');
        }

        try {
            $result = $this->action->execute(
                integration: $integration,
                action: $actionKey,
                params: (array) ($request->get('params') ?? []),
            );

            return Response::text(json_encode(['success' => true, 'result' => $result]));
        } catch (\Throwable $e) {
            throw $e;
        }
    }
}
