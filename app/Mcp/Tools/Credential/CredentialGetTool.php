<?php

namespace App\Mcp\Tools\Credential;

use App\Domain\Credential\Models\Credential;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class CredentialGetTool extends Tool
{
    use HasStructuredErrors;

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

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;

        $credential = Credential::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($validated['credential_id']);

        if (! $credential) {
            return $this->notFoundError('credential');
        }

        return Response::text(json_encode([
            'id' => $credential->id,
            'name' => $credential->name,
            'type' => $credential->credential_type->value,
            'status' => $credential->status->value,
            'description' => $credential->description,
            'expires_at' => $credential->expires_at?->toIso8601String(),
            'created_at' => $credential->created_at?->toIso8601String(),
            'creator_source' => $credential->creator_source->value ?? 'human',
            'creator_type' => $credential->creator_type,
            'creator_id' => $credential->creator_id,
            'note' => 'Secret data is masked for security.',
        ]));
    }
}
