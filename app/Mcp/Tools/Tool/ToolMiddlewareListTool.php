<?php

namespace App\Mcp\Tools\Tool;

use App\Domain\Tool\Models\Tool;
use App\Domain\Tool\Models\ToolMiddlewareConfig;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Attributes\IsIdempotent;
use Laravel\Mcp\Attributes\IsReadOnly;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool as McpTool;

#[IsReadOnly]
#[IsIdempotent]
class ToolMiddlewareListTool extends McpTool
{
    protected string $name = 'tool_middleware_list';

    protected string $description = 'List middleware configurations for a tool. Shows both built-in (always active) and custom middleware.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'tool_id' => $schema->string()
                ->description('Tool UUID')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $tool = Tool::findOrFail($request->string('tool_id'));

        $configs = ToolMiddlewareConfig::where('tool_id', $tool->id)
            ->orderBy('priority')
            ->get()
            ->map(fn (ToolMiddlewareConfig $c) => [
                'id' => $c->id,
                'label' => $c->label,
                'middleware_class' => $c->middleware_class,
                'config' => $c->config,
                'priority' => $c->priority,
                'enabled' => $c->enabled,
            ]);

        $builtIn = [
            ['label' => 'Rate Limit', 'class' => 'ToolRateLimit', 'always_active' => true],
            ['label' => 'Input Validation', 'class' => 'ToolInputValidation', 'always_active' => true],
            ['label' => 'Audit Log', 'class' => 'ToolAuditLog', 'always_active' => true],
        ];

        return Response::text(json_encode([
            'built_in' => $builtIn,
            'custom' => $configs,
        ], JSON_PRETTY_PRINT));
    }
}
