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

mcp_stdio — local MCP server via stdio. transport_config: {command, args, env}
mcp_http  — remote MCP server via HTTP/SSE. transport_config: {url, headers}
built_in  — host capability. transport_config depends on kind:
  bash: {kind:"bash", allowed_commands:[], allowed_paths:[]}
  filesystem: {kind:"filesystem", allowed_paths:[], read_only:false}
  ssh: {kind:"ssh", host, port, username, credential_id, allowed_commands:[]}

For SSH tools: create an ssh_key Credential first, then reference its ID in credential_id.
Host fingerprints are stored automatically on first connect (TOFU). Manage via tool_ssh_fingerprints.
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
                ->description('Tool type: mcp_stdio, mcp_http, built_in (default: mcp_stdio)')
                ->enum(['mcp_stdio', 'mcp_http', 'built_in'])
                ->default('mcp_stdio'),
            'transport_config' => $schema->object()
                ->description('Transport configuration (command, args, env for stdio; url, headers for http)'),
            'risk_level' => $schema->string()
                ->description('Risk classification: safe, read, write, destructive')
                ->enum(['safe', 'read', 'write', 'destructive']),
            'credential_id' => $schema->string()
                ->description('UUID of a linked Credential to use for this tool (optional; preferred over inline api_key)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'nullable|string|in:mcp_stdio,mcp_http,built_in',
            'transport_config' => 'nullable|array',
            'risk_level' => 'nullable|string|in:safe,read,write,destructive',
            'credential_id' => 'nullable|uuid|exists:credentials,id',
        ]);

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
