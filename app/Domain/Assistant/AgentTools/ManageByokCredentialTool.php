<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Shared\Models\TeamProviderCredential;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class ManageByokCredentialTool implements Tool
{
    public function name(): string
    {
        return 'manage_byok_credential';
    }

    public function description(): string
    {
        return 'Manage BYOK (Bring Your Own Key) LLM API credentials. List configured providers, set an API key, or delete a provider key. SECURITY: API keys are never returned after storage.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->required()->description('Action: list, set, delete'),
            'provider' => $schema->string()->description('LLM provider name (e.g. anthropic, openai, google). Required for set/delete.'),
            'api_key' => $schema->string()->description('The API key to store securely (for set action only). Will be encrypted.'),
        ];
    }

    public function handle(Request $request): string
    {
        $teamId = auth()->user()?->current_team_id;

        if (! $teamId) {
            return json_encode(['error' => 'No current team.']);
        }

        $action = $request->get('action');
        $provider = $request->get('provider');
        $apiKey = $request->get('api_key');

        if ($action === 'list') {
            $creds = TeamProviderCredential::where('team_id', $teamId)
                ->get(['id', 'provider', 'is_active', 'updated_at'])
                ->map(fn ($c) => [
                    'provider' => $c->provider,
                    'is_active' => $c->is_active,
                    'configured_at' => $c->updated_at?->toIso8601String(),
                    'note' => 'API key stored encrypted, cannot be retrieved.',
                ]);

            return json_encode(['providers' => $creds->toArray()]);
        }

        if ($action === 'set') {
            if (! $provider || ! $apiKey) {
                return json_encode(['error' => 'provider and api_key are required for set action.']);
            }

            TeamProviderCredential::updateOrCreate(
                ['team_id' => $teamId, 'provider' => $provider],
                ['credentials' => ['api_key' => $apiKey], 'is_active' => true],
            );

            $masked = strlen($apiKey) > 8
                ? str_repeat('*', strlen($apiKey) - 4).substr($apiKey, -4)
                : '****';

            return json_encode([
                'success' => true,
                'provider' => $provider,
                'masked_key' => $masked,
                'message' => "API key for '{$provider}' stored securely. Will not be shown again.",
            ]);
        }

        if ($action === 'delete') {
            if (! $provider) {
                return json_encode(['error' => 'provider is required for delete action.']);
            }

            $deleted = TeamProviderCredential::where('team_id', $teamId)
                ->where('provider', $provider)
                ->delete();

            if (! $deleted) {
                return json_encode(['error' => "No credential found for provider '{$provider}'."]);
            }

            return json_encode(['success' => true, 'provider' => $provider, 'message' => "API key for '{$provider}' deleted."]);
        }

        return json_encode(['error' => "Unknown action: {$action}. Use list, set, or delete."]);
    }
}
