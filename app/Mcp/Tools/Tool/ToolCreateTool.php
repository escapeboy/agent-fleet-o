<?php

namespace App\Mcp\Tools\Tool;

use App\Domain\Tool\Actions\CreateToolAction;
use App\Domain\Tool\Enums\ToolType;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ToolCreateTool extends Tool
{
    protected string $name = 'tool_create';

    protected string $description = 'Create a new tool (MCP server or built-in). Specify name and optionally description, type, and transport config.';

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
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'nullable|string|in:mcp_stdio,mcp_http,built_in',
            'transport_config' => 'nullable|array',
        ]);

        try {
            $tool = app(CreateToolAction::class)->execute(
                teamId: auth()->user()->current_team_id,
                name: $validated['name'],
                type: ToolType::from($validated['type'] ?? 'mcp_stdio'),
                description: $validated['description'] ?? '',
                transportConfig: $validated['transport_config'] ?? [],
            );

            return Response::text(json_encode([
                'success' => true,
                'tool_id' => $tool->id,
                'name' => $tool->name,
                'status' => $tool->status->value,
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
