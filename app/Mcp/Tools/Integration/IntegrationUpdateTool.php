<?php

namespace App\Mcp\Tools\Integration;

use App\Domain\Integration\Actions\UpdateIntegrationAction;
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
class IntegrationUpdateTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'integration_update';

    protected string $description = 'Update an existing integration: rename it, rotate credentials, replace driver config. Pass only the fields you want to change. Empty-string credential values preserve the existing secret. By default the integration is re-pinged after save so identity/health refresh immediately.';

    public function __construct(private readonly UpdateIntegrationAction $action) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'integration_id' => $schema->string()
                ->description('UUID of the integration to update')
                ->required(),
            'name' => $schema->string()
                ->description('New display name for the integration (optional)'),
            'credentials' => $schema->object()
                ->description('Partial credential update, e.g. {"api_key": "new_value"}. Use empty string to keep existing.'),
            'config' => $schema->object()
                ->description('Driver config replacement (full object).'),
            'reping' => $schema->boolean()
                ->description('Re-ping after save to refresh identity/health (default true).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? null;
        if (! $teamId) {
            return $this->permissionDeniedError('No team context.');
        }

        $integrationId = $request->get('integration_id');
        if (! $integrationId) {
            return $this->invalidArgumentError('integration_id is required.');
        }

        /** @var Integration|null $integration */
        $integration = Integration::withoutGlobalScopes()
            ->where('id', $integrationId)
            ->where('team_id', $teamId)
            ->first();

        if (! $integration) {
            return $this->notFoundError('integration', $integrationId);
        }

        try {
            $updated = $this->action->execute(
                integration: $integration,
                name: $request->get('name'),
                credentials: $request->get('credentials'),
                config: $request->get('config'),
                reping: (bool) ($request->get('reping') ?? true),
            );

            $meta = (array) ($updated->getAttribute('meta') ?? []);

            return Response::json([
                'success' => true,
                'integration' => [
                    'id' => $updated->id,
                    'driver' => $updated->getAttribute('driver'),
                    'name' => $updated->getAttribute('name'),
                    'status' => $updated->status->value,
                    'last_pinged_at' => $updated->last_pinged_at?->toIso8601String(),
                    'last_ping_status' => $updated->last_ping_status,
                    'last_ping_message' => $updated->last_ping_message,
                    'account' => $meta['account'] ?? null,
                ],
            ]);
        } catch (\RuntimeException $e) {
            return $this->invalidArgumentError($e->getMessage());
        } catch (\Throwable $e) {
            return $this->failedPreconditionError($e->getMessage());
        }
    }
}
