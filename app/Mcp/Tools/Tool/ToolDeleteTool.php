<?php

namespace App\Mcp\Tools\Tool;

use App\Domain\Tool\Actions\DeleteToolAction;
use App\Domain\Tool\Models\Tool as ToolModel;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class ToolDeleteTool extends Tool
{
    protected string $name = 'tool_delete';

    protected string $description = 'Delete a tool (soft delete). The tool will be detached from all agents and marked as deleted.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'tool_id' => $schema->string()
                ->description('The tool UUID')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'tool_id' => 'required|string',
        ]);

        $tool = ToolModel::find($validated['tool_id']);

        if (! $tool) {
            return Response::error('Tool not found.');
        }

        try {
            app(DeleteToolAction::class)->execute($tool);

            return Response::text(json_encode([
                'success' => true,
                'tool_id' => $validated['tool_id'],
                'deleted' => true,
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
