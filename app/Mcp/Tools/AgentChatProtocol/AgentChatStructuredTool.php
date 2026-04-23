<?php

declare(strict_types=1);

namespace App\Mcp\Tools\AgentChatProtocol;

use App\Domain\AgentChatProtocol\Actions\DispatchStructuredRequestAction;
use App\Domain\AgentChatProtocol\Models\ExternalAgent;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

#[AssistantTool('write')]
class AgentChatStructuredTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'agent_chat_structured';

    protected string $description = 'Send a structured_output_request to a remote agent with a JSON Schema. Returns the schema-enforced JSON output.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'external_agent_id' => $schema->string()->description('External agent UUID')->required(),
            'prompt' => $schema->string()->description('Natural-language prompt')->required(),
            'schema_json' => $schema->string()->description('JSON Schema as a JSON string')->required(),
            'session_token' => $schema->string()->description('Optional session token'),
        ];
    }

    public function handle(Request $request, DispatchStructuredRequestAction $action): Response
    {
        $validated = $request->validate([
            'external_agent_id' => 'required|string',
            'prompt' => 'required|string',
            'schema_json' => 'required|string',
            'session_token' => 'sometimes|string|max:128',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $decoded = json_decode($validated['schema_json'], true);
        if (! is_array($decoded) || $decoded === []) {
            return $this->invalidInputError('schema_json must be a non-empty JSON object');
        }

        $agent = ExternalAgent::withoutGlobalScopes()->where('team_id', $teamId)->find($validated['external_agent_id']);
        if (! $agent) {
            return $this->notFoundError('external_agent', $validated['external_agent_id']);
        }

        try {
            $result = $action->execute(
                externalAgent: $agent,
                prompt: $validated['prompt'],
                schema: $decoded,
                sessionToken: $validated['session_token'] ?? null,
            );
        } catch (\Throwable $e) {
            return $this->upstreamError($e->getMessage());
        }

        return Response::text(json_encode($result));
    }
}
