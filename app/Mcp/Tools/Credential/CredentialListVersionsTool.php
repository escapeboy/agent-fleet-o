<?php

namespace App\Mcp\Tools\Credential;

use App\Domain\Credential\Models\Credential;
use App\Domain\Credential\Models\CredentialVersion;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * List the version history of a credential.
 *
 * Secret data is never returned — only metadata (version number, note, created_at).
 */
#[IsReadOnly]
class CredentialListVersionsTool extends Tool
{
    protected string $name = 'credential_list_versions';

    protected string $description = 'List version history for a credential. Returns metadata only — secret values are never included. Versions are created automatically on each rotation.';

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
        $validated = $request->validate([
            'credential_id' => 'required|string',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }

        $credential = Credential::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($validated['credential_id']);

        if (! $credential) {
            return Response::error('Credential not found.');
        }

        $versions = CredentialVersion::withoutGlobalScopes()
            ->where('credential_id', $credential->id)
            ->orderByDesc('version_number')
            ->get(['id', 'version_number', 'note', 'created_by', 'created_at']);

        return Response::text(json_encode([
            'credential_id' => $credential->id,
            'credential_name' => $credential->name,
            'versions' => $versions->map(fn (CredentialVersion $v) => [
                'id' => $v->id,
                'version_number' => $v->version_number,
                'note' => $v->note,
                'created_by' => $v->created_by,
                'created_at' => $v->created_at->toIso8601String(),
            ])->values(),
        ]));
    }
}
