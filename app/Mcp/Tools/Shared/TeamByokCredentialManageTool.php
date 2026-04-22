<?php

namespace App\Mcp\Tools\Shared;

use App\Domain\Shared\Models\TeamProviderCredential;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class TeamByokCredentialManageTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'team_byok_credential_manage';

    protected string $description = 'Manage BYOK (Bring Your Own Key) LLM API credentials for the team. List configured providers, set an API key, or delete a provider credential. SECURITY: API keys are never returned after being stored.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description('Action to perform: list, set, delete')
                ->enum(['list', 'set', 'delete'])
                ->required(),
            'provider' => $schema->string()
                ->description('LLM provider name (e.g. anthropic, openai, google). Required for set and delete.'),
            'api_key' => $schema->string()
                ->description('The API key to store (for set action only). Will be encrypted at rest and never returned.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $user = Auth::user();
        $teamId = app('mcp.team_id') ?? $user?->current_team_id;

        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $action = $request->get('action');

        return match ($action) {
            'list' => $this->listCredentials($teamId),
            'set' => $this->setCredential($teamId, $request),
            'delete' => $this->deleteCredential($teamId, $request),
            default => $this->invalidArgumentError("Unknown action: {$action}"),
        };
    }

    private function listCredentials(string $teamId): Response
    {
        $credentials = TeamProviderCredential::where('team_id', $teamId)
            ->get(['id', 'provider', 'is_active', 'updated_at']);

        return Response::text(json_encode([
            'count' => $credentials->count(),
            'providers' => $credentials->map(fn ($c) => [
                'id' => $c->id,
                'provider' => $c->provider,
                'is_active' => $c->is_active,
                'configured_at' => $c->updated_at?->toIso8601String(),
                'note' => 'API key is stored encrypted and cannot be retrieved.',
            ])->toArray(),
        ]));
    }

    private function setCredential(string $teamId, Request $request): Response
    {
        $provider = $request->get('provider');
        $apiKey = $request->get('api_key');

        if (! $provider) {
            return $this->invalidArgumentError('provider is required for set action.');
        }

        if (! $apiKey) {
            return $this->invalidArgumentError('api_key is required for set action.');
        }

        TeamProviderCredential::updateOrCreate(
            ['team_id' => $teamId, 'provider' => $provider],
            [
                'credentials' => ['api_key' => $apiKey],
                'is_active' => true,
            ],
        );

        $maskedKey = strlen($apiKey) > 8
            ? str_repeat('*', strlen($apiKey) - 4).substr($apiKey, -4)
            : '****';

        return Response::text(json_encode([
            'success' => true,
            'provider' => $provider,
            'masked_key' => $maskedKey,
            'message' => "API key for '{$provider}' has been stored securely. The key will not be shown again.",
        ]));
    }

    private function deleteCredential(string $teamId, Request $request): Response
    {
        $provider = $request->get('provider');

        if (! $provider) {
            return $this->invalidArgumentError('provider is required for delete action.');
        }

        $deleted = TeamProviderCredential::where('team_id', $teamId)
            ->where('provider', $provider)
            ->delete();

        if (! $deleted) {
            return $this->notFoundError('credential for provider', $provider);
        }

        return Response::text(json_encode([
            'success' => true,
            'provider' => $provider,
            'message' => "API key for '{$provider}' has been deleted.",
        ]));
    }
}
