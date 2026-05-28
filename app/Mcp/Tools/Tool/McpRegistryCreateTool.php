<?php

namespace App\Mcp\Tools\Tool;

use App\Domain\Tool\Actions\CreateMcpRegistryEntryAction;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('destructive')]
class McpRegistryCreateTool extends Tool
{
    protected string $name = 'mcp_registry_create';

    protected string $description = 'Add an MCP server to the platform-curated registry. Platform admin operation: the entry becomes installable for every team. For stdio transport, connection must include command and args. For http, connection must include url. Optional bearer_token in connection is stored as-is — never paste raw production secrets through MCP.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->description('Human-readable name')
                ->required(),
            'description' => $schema->string()
                ->description('What this server does + when to install it'),
            'transport' => $schema->string()
                ->description('Transport type')
                ->enum(['mcp_stdio', 'mcp_http'])
                ->required(),
            'connection' => $schema->object()
                ->description('Transport-specific connection details (command/args/env for stdio, url/bearer_token for http)')
                ->required(),
            'trust_level' => $schema->string()
                ->description('Trust classification')
                ->enum(['platform_trusted', 'verified', 'community'])
                ->default('community'),
            'tool_allowlist' => $schema->array()
                ->items($schema->string())
                ->description('Restrict which MCP tools from the server are exposed. Null = expose all.'),
        ];
    }

    public function handle(Request $request, CreateMcpRegistryEntryAction $action): Response
    {
        $entry = $action->execute([
            'name' => (string) $request->get('name'),
            'description' => $request->get('description'),
            'transport' => (string) $request->get('transport'),
            'connection' => (array) $request->get('connection', []),
            'trust_level' => $request->get('trust_level', 'community'),
            'tool_allowlist' => $request->get('tool_allowlist'),
        ]);

        return Response::text(json_encode([
            'id' => $entry->id,
            'slug' => $entry->slug,
            'name' => $entry->name,
            'trust_level' => $entry->trust_level?->value,
            'is_active' => $entry->is_active,
        ]));
    }
}
