<?php

namespace App\Mcp\Tools\Tool;

use App\Domain\Tool\Actions\UpdateToolAction;
use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Models\Tool as ToolModel;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ToolUpdateTool extends Tool
{
    protected string $name = 'tool_update';

    protected string $description = 'Update an existing tool. Only provided fields will be changed.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'tool_id' => $schema->string()
                ->description('The tool UUID')
                ->required(),
            'name' => $schema->string()
                ->description('New tool name'),
            'description' => $schema->string()
                ->description('New tool description'),
            'status' => $schema->string()
                ->description('New status: active, disabled')
                ->enum(['active', 'disabled']),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'tool_id' => 'required|string',
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|string|in:active,disabled',
        ]);

        $tool = ToolModel::find($validated['tool_id']);

        if (! $tool) {
            return Response::error('Tool not found.');
        }

        try {
            // Handle status change directly on model
            if (isset($validated['status'])) {
                $tool->update(['status' => ToolStatus::from($validated['status'])]);
            }

            $result = app(UpdateToolAction::class)->execute(
                tool: $tool,
                name: $validated['name'] ?? null,
                description: $validated['description'] ?? null,
            );

            return Response::text(json_encode([
                'success' => true,
                'tool_id' => $result->id,
                'name' => $result->name,
                'status' => $result->status->value,
                'updated_fields' => array_keys(array_filter([
                    'name' => $validated['name'] ?? null,
                    'description' => $validated['description'] ?? null,
                    'status' => $validated['status'] ?? null,
                ], fn ($v) => $v !== null)),
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
