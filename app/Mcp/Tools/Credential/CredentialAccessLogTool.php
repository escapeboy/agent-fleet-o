<?php

namespace App\Mcp\Tools\Credential;

use App\Domain\Credential\Models\Credential;
use App\Domain\Credential\Models\CredentialAccessLog;
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
class CredentialAccessLogTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'credential_access_log';

    protected string $description = 'Retrieve the access log for a credential, showing when and how it was resolved by agents.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'credential_id' => $schema->string()
                ->description('The credential UUID')
                ->required(),
            'limit' => $schema->integer()
                ->description('Maximum entries to return (default: 50)')
                ->minimum(1)
                ->maximum(200),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'credential_id' => 'required|string',
            'limit' => 'integer|min:1|max:200',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;

        $credential = Credential::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($validated['credential_id']);

        if (! $credential) {
            return $this->notFound('Credential', $validated['credential_id']);
        }

        $limit = $validated['limit'] ?? 50;

        $logs = CredentialAccessLog::withoutGlobalScopes()
            ->where('credential_id', $validated['credential_id'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return Response::text(json_encode([
            'credential_id' => $validated['credential_id'],
            'credential_name' => $credential->name,
            'count' => $logs->count(),
            'logs' => $logs->map(fn ($l) => [
                'id' => $l->id,
                'resolved_for' => $l->resolved_for,
                'agent_id' => $l->agent_id,
                'tool_id' => $l->tool_id,
                'target_domain' => $l->target_domain,
                'allowed' => $l->allowed,
                'created_at' => $l->created_at?->toIso8601String(),
            ])->values()->toArray(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
