<?php

namespace App\Mcp\Tools\Tool;

use App\Domain\Tool\Models\Tool as ToolModel;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class ToolListTool extends Tool
{
    protected string $name = 'tool_list';

    protected string $description = 'List tools with optional status filter. Returns id, name, type, status, and description.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->description('Filter by status: active, disabled')
                ->enum(['active', 'disabled']),
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

        $limit = min((int) ($request->get('limit', 10)), 100);

        $tools = $query->limit($limit)->get();

        return Response::text(json_encode([
            'count' => $tools->count(),
            'tools' => $tools->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'type' => $t->type->value,
                'status' => $t->status->value,
                'description' => $t->description,
            ])->toArray(),
        ]));
    }
}
