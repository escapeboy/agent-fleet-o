<?php

namespace App\Mcp\Tools\Credential;

use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Cache;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class CredentialOAuthFinalizeTool extends Tool
{
    protected string $name = 'credential_oauth_finalize';

    protected string $description = 'Checks the status of an OAuth flow initiated with credential_oauth_initiate. If the user has completed authorization and tokens are available, stores them as a pending_review credential. Returns the credential ID on success.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'correlation_id' => $schema->string()
                ->description('The correlation_id returned by credential_oauth_initiate')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['correlation_id' => 'required|string']);

        $session = Cache::get("oauth_session:{$validated['correlation_id']}");

        if (! $session) {
            return Response::error('OAuth session not found or expired. Sessions expire after 10 minutes.');
        }

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;

        if ($session['team_id'] !== $teamId) {
            return Response::error('OAuth session belongs to a different team.');
        }

        $status = $session['status'] ?? 'pending';

        if ($status === 'pending') {
            return Response::text(json_encode([
                'correlation_id' => $validated['correlation_id'],
                'status' => 'pending',
                'service_name' => $session['service_name'],
                'message' => 'The OAuth flow has not been completed yet. The user must visit the authorization URL and grant access.',
            ]));
        }

        if ($status === 'completed' && ! empty($session['credential_id'])) {
            return Response::text(json_encode([
                'correlation_id' => $validated['correlation_id'],
                'status' => 'completed',
                'credential_id' => $session['credential_id'],
                'credential_name' => $session['credential_name'],
                'service_name' => $session['service_name'],
                'message' => 'OAuth credential stored as pending_review. A human must approve it before it can be used.',
            ]));
        }

        return Response::text(json_encode([
            'correlation_id' => $validated['correlation_id'],
            'status' => $status,
            'service_name' => $session['service_name'],
            'message' => "OAuth session status: {$status}",
        ]));
    }
}
