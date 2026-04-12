<?php

namespace App\Mcp\Tools\Credential;

use App\Domain\Credential\Models\Credential;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use App\Mcp\Attributes\AssistantTool;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class CredentialListTool extends Tool
{
    protected string $name = 'credential_list';

    protected string $description = 'List credentials with optional filters. Returns id, name, type, status, creator_source, and expiry. Never includes secret data.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->description('Filter by status: active, disabled, pending_review')
                ->enum(['active', 'disabled', 'pending_review']),
            'creator_source' => $schema->string()
                ->description('Filter by creator source: human, agent, system')
                ->enum(['human', 'agent', 'system']),
            'limit' => $schema->integer()
                ->description('Max results to return (default 10, max 100)')
                ->default(10),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;

        // Use withoutGlobalScopes + explicit where('team_id') for defence-in-depth:
        // TeamScope's orWhereNull() would otherwise expose platform credentials (team_id=null).
        $query = Credential::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->orderBy('name');

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        if ($creatorSource = $request->get('creator_source')) {
            $query->where('creator_source', $creatorSource);
        }

        $limit = min((int) ($request->get('limit', 10)), 100);

        $credentials = $query->limit($limit)->get();

        return Response::text(json_encode([
            'count' => $credentials->count(),
            'credentials' => $credentials->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'type' => $c->credential_type->value,
                'status' => $c->status->value,
                'creator_source' => $c->creator_source?->value ?? 'human',
                'expires_at' => $c->expires_at?->toIso8601String(),
            ])->toArray(),
        ]));
    }
}
