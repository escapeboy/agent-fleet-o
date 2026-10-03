<?php

namespace App\Mcp\Tools\Tool;

use App\Domain\Tool\Models\Tool;
use App\Domain\Tool\Services\McpHttpClient;
use App\Domain\Tool\Services\ToolDefinitionPinner;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool as McpTool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[IsDestructive]
#[AssistantTool('write')]
class ToolProbeRemoteMcpTool extends McpTool
{
    use HasStructuredErrors;

    protected string $name = 'tool_probe_remote_mcp';

    protected string $description = 'Connect to a remote MCP HTTP/SSE server, fetch its tools/list, and store the definitions in the Tool record. If definition pinning is on and the tool already has definitions, a change is held for human approval (outcome=pending) instead of being applied. Call this after creating an mcp_http Tool to populate its tool_definitions so agents can use its capabilities.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'tool_id' => $schema->string()
                ->description('UUID of the Tool record with type=mcp_http to probe')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $toolId = $request->get('tool_id');

        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null) ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }
        $tool = Tool::withoutGlobalScopes()->where('team_id', $teamId)->find($toolId);

        if (! $tool) {
            return $this->notFoundError('tool');
        }

        if ($tool->type->value !== 'mcp_http') {
            return $this->failedPreconditionError("Tool must be of type mcp_http (got: {$tool->type->value}).");
        }

        $url = $tool->transport_config['url'] ?? null;
        if (! $url) {
            return $this->failedPreconditionError('Tool has no URL in transport_config.');
        }

        $credentials = (array) $tool->credentials;
        $authHeader = $credentials['api_key'] ?? $credentials['bearer_token'] ?? null;
        $headers = $authHeader ? ['Authorization' => "Bearer {$authHeader}"] : [];

        try {
            $tools = app(McpHttpClient::class)->listTools($url, $headers);
        } catch (\Throwable $e) {
            throw $e;
        }

        if (empty($tools)) {
            return $this->failedPreconditionError('MCP server returned no tools.');
        }

        // A change against already-approved definitions is held for approval
        // when definition pinning is on (ToolDefinitionPinner).
        $pinner = app(ToolDefinitionPinner::class);
        $definitions = $pinner->normalize($tools);
        $outcome = $pinner->sync($tool, $tools);

        return Response::text(json_encode([
            'success' => true,
            'tool_id' => $tool->id,
            'tool_name' => $tool->name,
            'definitions_count' => count($definitions),
            'tools' => array_column($definitions, 'name'),
            'outcome' => $outcome,
        ]));
    }
}
