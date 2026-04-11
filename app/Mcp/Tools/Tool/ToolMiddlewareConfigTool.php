<?php

namespace App\Mcp\Tools\Tool;

use App\Domain\Tool\Models\Tool;
use App\Domain\Tool\Models\ToolMiddlewareConfig;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool as McpTool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class ToolMiddlewareConfigTool extends McpTool
{
    protected string $name = 'tool_middleware_config';

    protected string $description = 'Add or update a custom middleware configuration for a tool. Use to configure rate limits, input validation rules, or custom middleware.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'tool_id' => $schema->string()
                ->description('Tool UUID')
                ->required(),
            'middleware_class' => $schema->string()
                ->description('Fully qualified class name of the middleware')
                ->required(),
            'label' => $schema->string()
                ->description('Display label for this middleware')
                ->required(),
            'config' => $schema->object()
                ->description('Middleware-specific configuration'),
            'priority' => $schema->number()
                ->description('Execution priority (lower runs first, default 100)')
                ->default(100),
            'enabled' => $schema->boolean()
                ->description('Whether middleware is active')
                ->default(true),
        ];
    }

    public function handle(Request $request): Response
    {
        $tool = Tool::findOrFail($request->string('tool_id'));

        $config = ToolMiddlewareConfig::updateOrCreate(
            [
                'tool_id' => $tool->id,
                'middleware_class' => $request->string('middleware_class'),
            ],
            [
                'label' => $request->string('label'),
                'config' => $request->object('config') ?? [],
                'priority' => (int) ($request->number('priority') ?? 100),
                'enabled' => $request->boolean('enabled') ?? true,
            ],
        );

        return Response::text("Middleware '{$config->label}' configured for tool '{$tool->name}' (ID: {$config->id})");
    }
}
