<?php

namespace App\Mcp\Tools\Signal;

use App\Domain\Integration\Models\Integration;
use App\Domain\Signal\Actions\CreateConnectorSubscriptionAction;
use App\Domain\Signal\Actions\DeleteConnectorSubscriptionAction;
use App\Domain\Signal\Models\ConnectorSignalSubscription;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * MCP tool for managing ConnectorSignalSubscriptions.
 *
 * Agents can list, create, toggle, and delete per-source webhook subscriptions
 * backed by OAuth integrations (GitHub, Linear, Jira, etc.).
 */
class ConnectorSubscriptionTool extends Tool
{
    protected string $name = 'connector_subscription_manage';

    protected string $description = 'Manage per-source webhook subscriptions backed by OAuth integrations (GitHub repos, Linear teams, Jira projects). Each subscription gets a unique webhook URL.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description('Action: list | get | create | toggle | delete')
                ->enum(['list', 'get', 'create', 'toggle', 'delete'])
                ->required(),

            // list / get / toggle / delete
            'subscription_id' => $schema->string()
                ->description('Subscription UUID (required for get/toggle/delete)'),

            // create
            'integration_id' => $schema->string()
                ->description('Integration UUID to bind this subscription to (required for create)'),
            'name' => $schema->string()
                ->description('Friendly label for the subscription (required for create)'),
            'filter_config' => $schema->object()
                ->description(
                    'Driver-specific filter config. '.
                    'GitHub: {repo, filter_branches, event_types}. '.
                    'Linear: {team_id, resource_types, filter_actions}. '.
                    'Jira: {project_key, webhook_events}.',
                ),
        ];
    }

    public function handle(Request $request): Response
    {
        $user = Auth::user();
        $teamId = $user?->current_team_id;

        if (! $teamId) {
            return Response::error('No current team.');
        }

        $action = $request->get('action', 'list');

        return match ($action) {
            'list' => $this->listSubscriptions($teamId),
            'get' => $this->getSubscription($request, $teamId),
            'create' => $this->createSubscription($request, $teamId),
            'toggle' => $this->toggleSubscription($request, $teamId),
            'delete' => $this->deleteSubscription($request, $teamId),
            default => Response::error("Unknown action: {$action}"),
        };
    }

    private function listSubscriptions(string $teamId): Response
    {
        $subs = ConnectorSignalSubscription::withoutGlobalScopes()
            ->with('integration:id,name,driver')
            ->where('team_id', $teamId)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn ($s) => $this->formatSubscription($s));

        return Response::text(json_encode(['subscriptions' => $subs], JSON_PRETTY_PRINT));
    }

    private function getSubscription(Request $request, string $teamId): Response
    {
        $id = $request->get('subscription_id');
        if (! $id) {
            return Response::error('subscription_id is required');
        }

        $sub = ConnectorSignalSubscription::withoutGlobalScopes()
            ->with('integration:id,name,driver')
            ->where('team_id', $teamId)
            ->find($id);

        if (! $sub) {
            return Response::error('Subscription not found.');
        }

        return Response::text(json_encode($this->formatSubscription($sub), JSON_PRETTY_PRINT));
    }

    private function createSubscription(Request $request, string $teamId): Response
    {
        $integrationId = $request->get('integration_id');
        $name = $request->get('name');

        if (! $integrationId || ! $name) {
            return Response::error('integration_id and name are required');
        }

        $integration = Integration::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($integrationId);

        if (! $integration) {
            return Response::error('Integration not found.');
        }

        $filterConfig = (array) ($request->get('filter_config') ?? []);

        try {
            $action = app(CreateConnectorSubscriptionAction::class);
            $sub = $action->execute(
                integration: $integration,
                name: $name,
                filterConfig: $filterConfig,
            );

            return Response::text(json_encode([
                'created' => true,
                'subscription_id' => $sub->id,
                'webhook_url' => $sub->webhookUrl(),
                'webhook_status' => $sub->webhook_status,
                'message' => 'Subscription created. Webhook registration is queued.',
            ], JSON_PRETTY_PRINT));
        } catch (\Throwable $e) {
            return Response::error('Failed to create subscription: '.$e->getMessage());
        }
    }

    private function toggleSubscription(Request $request, string $teamId): Response
    {
        $id = $request->get('subscription_id');
        if (! $id) {
            return Response::error('subscription_id is required');
        }

        $sub = ConnectorSignalSubscription::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($id);

        if (! $sub) {
            return Response::error('Subscription not found.');
        }

        $sub->update(['is_active' => ! $sub->is_active]);

        return Response::text(json_encode([
            'subscription_id' => $sub->id,
            'is_active' => ! ($sub->getOriginal('is_active')),
        ], JSON_PRETTY_PRINT));
    }

    private function deleteSubscription(Request $request, string $teamId): Response
    {
        $id = $request->get('subscription_id');
        if (! $id) {
            return Response::error('subscription_id is required');
        }

        $sub = ConnectorSignalSubscription::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($id);

        if (! $sub) {
            return Response::error('Subscription not found.');
        }

        $action = app(DeleteConnectorSubscriptionAction::class);
        $action->execute($sub);

        return Response::text(json_encode(['deleted' => true, 'subscription_id' => $id], JSON_PRETTY_PRINT));
    }

    /** @return array<string, mixed> */
    private function formatSubscription(ConnectorSignalSubscription $s): array
    {
        return [
            'id' => $s->id,
            'name' => $s->name,
            'driver' => $s->driver,
            'integration' => $s->integration
                ? ['id' => $s->integration->id, 'name' => $s->integration->name]
                : null,
            'webhook_url' => $s->webhookUrl(),
            'webhook_status' => $s->webhook_status,
            'webhook_expiring_soon' => $s->isWebhookExpiringSoon(),
            'is_active' => $s->is_active,
            'signal_count' => $s->signal_count,
            'last_signal_at' => $s->last_signal_at?->toIso8601String(),
            'filter_config' => $s->filter_config,
            'created_at' => $s->created_at?->toIso8601String(),
        ];
    }
}
