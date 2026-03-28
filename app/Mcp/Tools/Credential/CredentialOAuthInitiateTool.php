<?php

namespace App\Mcp\Tools\Credential;

use App\Domain\Agent\Models\Agent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class CredentialOAuthInitiateTool extends Tool
{
    protected string $name = 'credential_oauth_initiate';

    protected string $description = 'Initiates an OAuth flow on behalf of an agent. Returns a correlation_id and a URL the user must visit to complete authorization. The agent never sees the raw token — call credential_oauth_finalize with the correlation_id after the user authorizes.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'service_name' => $schema->string()
                ->description('Human-readable name of the service being authorized (e.g. "GitHub", "Slack")')
                ->required(),
            'agent_id' => $schema->string()
                ->description('UUID of the agent initiating this OAuth flow')
                ->required(),
            'scopes' => $schema->array()
                ->description('List of OAuth scopes to request (e.g. ["read:user", "repo"])'),
            'credential_name' => $schema->string()
                ->description('Name to give the stored credential once OAuth completes'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'service_name' => 'required|string|max:255',
            'agent_id' => 'required|uuid',
            'scopes' => 'nullable|array',
            'credential_name' => 'nullable|string|max:255',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;

        $agent = Agent::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($validated['agent_id']);

        if (! $agent) {
            return Response::error('Agent not found.');
        }

        $correlationId = (string) Str::uuid();

        // Store the pending OAuth session in cache (10 minute TTL)
        Cache::put("oauth_session:{$correlationId}", [
            'team_id' => $teamId,
            'agent_id' => $agent->id,
            'agent_name' => $agent->name,
            'service_name' => $validated['service_name'],
            'scopes' => $validated['scopes'] ?? [],
            'credential_name' => $validated['credential_name'] ?? $validated['service_name'].' OAuth',
            'status' => 'pending',
            'initiated_at' => now()->toIso8601String(),
        ], 600);

        return Response::text(json_encode([
            'correlation_id' => $correlationId,
            'service_name' => $validated['service_name'],
            'scopes' => $validated['scopes'] ?? [],
            'status' => 'pending',
            'instruction' => "A human must visit the OAuth authorization URL for '{$validated['service_name']}' and complete the flow. Once done, call credential_oauth_finalize with correlation_id='{$correlationId}' to store the resulting credential.",
            'note' => 'OAuth callback URL configuration is required in team settings. The credential will be created as pending_review and must be approved before use.',
        ]));
    }
}
