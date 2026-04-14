<?php

namespace App\Mcp\Tools\Tool;

use App\Domain\Tool\Models\Tool as ToolModel;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use App\Mcp\Attributes\AssistantTool;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class ToolListTool extends Tool
{
    protected string $name = 'tool_list';

    protected string $description = 'List tools with optional filters. Returns id, name, type, status, is_platform, and description.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->description('Filter by status: active, disabled')
                ->enum(['active', 'disabled']),
            'platform_only' => $schema->boolean()
                ->description('If true, return only platform-level tools (shared across all teams)'),
            'limit' => $schema->integer()
                ->description('Max results to return (default 10, max 100)')
                ->default(10),
        ];
    }

    public function handle(Request $request): Response
    {
        $query = ToolModel::query()->orderBy('name');

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        if ($request->get('platform_only')) {
            $query->where('is_platform', true);
        }

        $limit = min((int) ($request->get('limit', 10)), 100);

        $tools = $query->limit($limit)->get();

        return Response::text(json_encode([
            'count' => $tools->count(),
            'tools' => $tools->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'type' => $t->type->value,
                'status' => $t->status->value,
                'is_platform' => $t->isPlatformTool(),
                'description' => $t->description,
            ])->toArray(),
        ]));
    }
}
