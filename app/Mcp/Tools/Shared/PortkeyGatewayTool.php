<?php

namespace App\Mcp\Tools\Shared;

use App\Domain\Shared\Models\TeamProviderCredential;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

/**
 * MCP tool for managing the Portkey AI Gateway integration.
 *
 * Portkey (https://portkey.ai) is an OpenAI-compatible AI gateway that
 * provides observability, semantic caching, fallbacks, load balancing, and
 * unified access to 250+ LLM providers. When a Portkey API key is configured
 * for a team, all their AI requests are routed through Portkey instead of
 * directly to providers.
 *
 * Actions:
 *   status     — check whether Portkey is configured for the current team
 *   configure  — store/update the Portkey API key (and optional virtual key)
 *   remove     — delete the Portkey credential (disables the gateway)
 */
#[IsDestructive]
#[AssistantTool('write')]
class PortkeyGatewayTool extends Tool
{
    protected string $name = 'portkey_gateway_manage';

    protected string $description = 'Manage the Portkey AI Gateway for the team. '
        .'Portkey routes all AI requests through api.portkey.ai/v1 and provides observability, '
        .'semantic caching, fallbacks, and access to 250+ LLM providers. '
        .'Actions: status, configure, remove. '
        .'SECURITY: API keys are encrypted at rest and never returned after storage.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description('Action: status | configure | remove')
                ->enum(['status', 'configure', 'remove'])
                ->required(),
            'api_key' => $schema->string()
                ->description('Portkey API key (required for configure). Found at https://app.portkey.ai/api-keys'),
            'virtual_key' => $schema->string()
                ->description('Optional Portkey virtual key for provider-specific routing (configure only)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? Auth::user()?->current_team_id;

        if (! $teamId) {
            return Response::error('No current team.');
        }

        $action = $request->get('action');

        return match ($action) {
            'status' => $this->handleStatus($teamId),
            'configure' => $this->handleConfigure($teamId, $request),
            'remove' => $this->handleRemove($teamId),
            default => Response::error("Unknown action '{$action}'. Valid: status, configure, remove"),
        };
    }

    private function handleStatus(string $teamId): Response
    {
        $credential = TeamProviderCredential::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('provider', 'portkey')
            ->first();

        if (! $credential) {
            return Response::text(json_encode([
                'configured' => false,
                'message' => 'Portkey is not configured. Use action=configure with your API key to enable.',
                'docs' => 'https://portkey.ai/docs',
            ]));
        }

        $hasVirtualKey = ! empty($credential->credentials['virtual_key'] ?? null);

        return Response::text(json_encode([
            'configured' => true,
            'is_active' => $credential->is_active,
            'has_virtual_key' => $hasVirtualKey,
            'configured_at' => $credential->updated_at?->toIso8601String(),
            'message' => $credential->is_active
                ? 'Portkey gateway is active. All AI requests for this team are routed through Portkey.'
                : 'Portkey credential exists but is inactive.',
            'note' => 'API key is stored encrypted and cannot be retrieved.',
        ]));
    }

    private function handleConfigure(string $teamId, Request $request): Response
    {
        $apiKey = $request->get('api_key');

        if (! $apiKey) {
            return Response::error('api_key is required for configure action. Get your key at https://app.portkey.ai/api-keys');
        }

        // Basic format validation — Portkey keys start with "pk-"
        if (! str_starts_with((string) $apiKey, 'pk-')) {
            return Response::error('Invalid Portkey API key format. Keys must start with "pk-". Get your key at https://app.portkey.ai/api-keys');
        }

        $virtualKey = $request->get('virtual_key');
        $credentials = ['api_key' => $apiKey];

        if ($virtualKey) {
            $credentials['virtual_key'] = $virtualKey;
        }

        TeamProviderCredential::updateOrCreate(
            ['team_id' => $teamId, 'provider' => 'portkey'],
            [
                'credentials' => $credentials,
                'is_active' => true,
            ],
        );

        $maskedKey = strlen($apiKey) > 8
            ? str_repeat('*', strlen($apiKey) - 4).substr($apiKey, -4)
            : '****';

        return Response::text(json_encode([
            'success' => true,
            'masked_key' => $maskedKey,
            'has_virtual_key' => $virtualKey !== null,
            'message' => 'Portkey API key stored securely. All AI requests for this team will now be routed through Portkey.',
            'note' => 'The key will not be shown again.',
        ]));
    }

    private function handleRemove(string $teamId): Response
    {
        $deleted = TeamProviderCredential::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('provider', 'portkey')
            ->delete();

        if (! $deleted) {
            return Response::error('No Portkey credential found for this team.');
        }

        return Response::text(json_encode([
            'success' => true,
            'message' => 'Portkey credential removed. AI requests will now go directly to providers.',
        ]));
    }
}
