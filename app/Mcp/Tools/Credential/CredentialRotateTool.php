<?php

namespace App\Mcp\Tools\Credential;

use App\Domain\Credential\Actions\RotateCredentialSecretAction;
use App\Domain\Credential\Models\Credential;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class CredentialRotateTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'credential_rotate';

    protected string $description = 'Rotate the secret data for a credential. Replaces the stored secret with new values and updates last_rotated_at.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'credential_id' => $schema->string()
                ->description('The credential UUID')
                ->required(),
            'secret_data' => $schema->object()
                ->description('New secret data object (e.g. {"api_key": "sk-..."} or {"username": "...", "password": "..."})')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'credential_id' => 'required|string',
            'secret_data' => 'required|array',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }
        $credential = Credential::withoutGlobalScopes()->where('team_id', $teamId)->find($validated['credential_id']);

        if (! $credential) {
            return $this->notFoundError('credential');
        }

        try {
            app(RotateCredentialSecretAction::class)->execute($credential, $validated['secret_data']);

            $credential->refresh();

            return Response::text(json_encode([
                'success' => true,
                'id' => $credential->id,
                'name' => $credential->name,
                'credential_type' => $credential->credential_type->value,
                'status' => $credential->status->value,
                'last_rotated_at' => $credential->last_rotated_at?->toIso8601String(),
                'updated_at' => $credential->updated_at->toIso8601String(),
            ]));
        } catch (\Throwable $e) {
            throw $e;
        }
    }
}
