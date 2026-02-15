<?php

namespace App\Mcp\Tools\Credential;

use App\Domain\Credential\Models\Credential;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class CredentialGetTool extends Tool
{
    protected string $name = 'credential_get';

    protected string $description = 'Get detailed information about a specific credential. Never includes secret data for security.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'credential_id' => $schema->string()
                ->description('The credential UUID')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['credential_id' => 'required|string']);

        $credential = Credential::find($validated['credential_id']);

        if (! $credential) {
            return Response::error('Credential not found.');
        }

        return Response::text(json_encode([
            'id' => $credential->id,
            'name' => $credential->name,
            'type' => $credential->credential_type->value,
            'status' => $credential->status->value,
            'description' => $credential->description,
            'expires_at' => $credential->expires_at?->toIso8601String(),
            'created_at' => $credential->created_at?->toIso8601String(),
            'note' => 'Secret data is masked for security.',
        ]));
    }
}
