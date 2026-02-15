<?php

namespace App\Mcp\Tools\Credential;

use App\Domain\Credential\Actions\CreateCredentialAction;
use App\Domain\Credential\Enums\CredentialType;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class CredentialCreateTool extends Tool
{
    protected string $name = 'credential_create';

    protected string $description = 'Creates a credential. WARNING: secret_data will be stored encrypted but flows through the current session.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->description('Credential name')
                ->required(),
            'type' => $schema->string()
                ->description('Credential type: api_key, oauth2, basic_auth, bearer_token, custom')
                ->enum(['api_key', 'oauth2', 'basic_auth', 'bearer_token', 'custom'])
                ->required(),
            'secret_data' => $schema->object()
                ->description('Secret data object (e.g. {"token": "..."} or {"username": "...", "password": "..."})')
                ->required(),
            'description' => $schema->string()
                ->description('Credential description'),
            'expires_at' => $schema->string()
                ->description('Expiration date in ISO 8601 format (e.g. 2025-12-31T23:59:59Z)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|string|in:api_key,oauth2,basic_auth,bearer_token,custom',
            'secret_data' => 'required|array',
            'description' => 'nullable|string',
            'expires_at' => 'nullable|string|date',
        ]);

        try {
            $credential = app(CreateCredentialAction::class)->execute(
                teamId: auth()->user()->current_team_id,
                name: $validated['name'],
                credentialType: CredentialType::from($validated['type']),
                secretData: $validated['secret_data'],
                description: $validated['description'] ?? null,
                expiresAt: $validated['expires_at'] ?? null,
            );

            return Response::text(json_encode([
                'success' => true,
                'credential_id' => $credential->id,
                'name' => $credential->name,
                'type' => $credential->credential_type->value,
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
