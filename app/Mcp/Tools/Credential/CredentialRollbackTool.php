<?php

namespace App\Mcp\Tools\Credential;

use App\Domain\Credential\Actions\RollbackCredentialVersionAction;
use App\Domain\Credential\Models\Credential;
use App\Domain\Credential\Models\CredentialVersion;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

/**
 * Rollback a credential to a previous version's secret_data.
 *
 * Append-only: the current value is snapshotted before the restore,
 * so no history is ever lost.
 */
#[IsDestructive]
#[AssistantTool('write')]
class CredentialRollbackTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'credential_rollback';

    protected string $description = 'Rollback a credential to a previous version. The current secret is snapshotted first, so history is preserved. Use credential_list_versions to find the version_id.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'credential_id' => $schema->string()
                ->description('The credential UUID')
                ->required(),
            'version_id' => $schema->string()
                ->description('The CredentialVersion UUID to restore (from credential_list_versions)')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'credential_id' => 'required|string',
            'version_id' => 'required|string',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $credential = Credential::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($validated['credential_id']);

        if (! $credential) {
            return $this->notFoundError('credential');
        }

        $version = CredentialVersion::withoutGlobalScopes()
            ->where('credential_id', $credential->id)
            ->find($validated['version_id']);

        if (! $version) {
            return $this->notFoundError('version');
        }

        try {
            app(RollbackCredentialVersionAction::class)->execute($credential, $version);

            $credential->refresh();

            return Response::text(json_encode([
                'success' => true,
                'credential_id' => $credential->id,
                'credential_name' => $credential->name,
                'rolled_back_to_version' => $version->version_number,
                // @phpstan-ignore method.nonObject
                'last_rotated_at' => $credential->last_rotated_at?->toIso8601String(),
            ]));
        } catch (\Throwable $e) {
            throw $e;
        }
    }
}
