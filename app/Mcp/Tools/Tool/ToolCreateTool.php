<?php

namespace App\Mcp\Tools\Tool;

use App\Domain\Tool\Actions\CreateToolAction;
use App\Domain\Tool\Enums\ToolRiskLevel;
use App\Domain\Tool\Enums\ToolType;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ToolCreateTool extends Tool
{
    protected string $name = 'tool_create';

    protected string $description = <<<'DESC'
Create a new tool. Supported types:

mcp_stdio  — local MCP server via stdio. transport_config: {command, args, env}
mcp_http   — remote MCP server via HTTP/SSE. transport_config: {url, headers}
mcp_bridge — MCP server on a bridge daemon. transport_config: {bridge_server_name}
built_in   — host capability. transport_config depends on kind:
  bash: {kind:"bash", allowed_commands:[], allowed_paths:[]}
  filesystem: {kind:"filesystem", allowed_paths:[], read_only:false}
  ssh: {kind:"ssh", host, port, username, credential_id, allowed_commands:[]}

For mcp_bridge: the bridge_server_name must match a server name reported by the team's bridge daemon.
For SSH tools: create an ssh_key Credential first, then reference its ID in credential_id.
Host fingerprints are stored automatically on first connect (TOFU). Manage via tool_ssh_fingerprints.

network_policy controls Docker sandbox egress for built_in bash tools (requires tool_network_policies plan feature).
Format: {"rules":[{"protocol":"tcp","host":"api.example.com","port":443}],"default_action":"deny"}
DESC;

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->description('Tool name')
                ->required(),
            'description' => $schema->string()
                ->description('Tool description'),
            'type' => $schema->string()
                ->description('Tool type: mcp_stdio, mcp_http, mcp_bridge, built_in (default: mcp_stdio)')
                ->enum(['mcp_stdio', 'mcp_http', 'mcp_bridge', 'built_in'])
                ->default('mcp_stdio'),
            'transport_config' => $schema->object()
                ->description('Transport configuration (command, args, env for stdio; url, headers for http)'),
            'risk_level' => $schema->string()
                ->description('Risk classification: safe, read, write, destructive')
                ->enum(['safe', 'read', 'write', 'destructive']),
            'credential_id' => $schema->string()
                ->description('UUID of a linked Credential to use for this tool (optional; preferred over inline api_key)'),
            'network_policy' => $schema->string()
                ->description('JSON string defining egress rules for Docker sandbox (built_in bash only). Example: {"rules":[{"protocol":"tcp","host":"api.example.com","port":443}],"default_action":"deny"}'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'nullable|string|in:mcp_stdio,mcp_http,mcp_bridge,built_in',
            'transport_config' => 'nullable|array',
            'risk_level' => 'nullable|string|in:safe,read,write,destructive',
            'credential_id' => 'nullable|uuid|exists:credentials,id',
            'network_policy' => 'nullable|string',
        ]);

        // Parse optional network_policy JSON string into an array
        $networkPolicy = null;
        if (! empty($validated['network_policy'])) {
            $networkPolicy = json_decode($validated['network_policy'], true);
            if (! is_array($networkPolicy)) {
                return Response::error('network_policy must be a valid JSON object.');
            }
        }

        try {
            $tool = app(CreateToolAction::class)->execute(
                teamId: auth()->user()->current_team_id,
                name: $validated['name'],
                type: ToolType::from($validated['type'] ?? 'mcp_stdio'),
                description: $validated['description'] ?? '',
                transportConfig: $validated['transport_config'] ?? [],
                credentialId: $validated['credential_id'] ?? null,
            );

            if (! empty($validated['risk_level'])) {
                $tool->update(['risk_level' => ToolRiskLevel::from($validated['risk_level'])]);
            }

            if ($networkPolicy !== null) {
                $tool->update(['network_policy' => $networkPolicy]);
            }

            return Response::text(json_encode([
                'success' => true,
                'tool_id' => $tool->id,
                'name' => $tool->name,
                'status' => $tool->status->value,
                'risk_level' => $tool->risk_level?->value,
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
