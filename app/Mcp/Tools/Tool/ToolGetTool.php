<?php

namespace App\Mcp\Tools\Tool;

use App\Domain\Tool\Models\Tool as ToolModel;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class ToolGetTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'tool_get';

    protected string $description = 'Get detailed information about a specific tool including type, status, description, and sanitized transport config.';

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
        $validated = $request->validate(['tool_id' => 'required|string']);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }
        $tool = ToolModel::withoutGlobalScopes()->where('team_id', $teamId)->find($validated['tool_id']);

        if (! $tool) {
            return $this->notFoundError('tool');
        }

        $transportConfig = $tool->transport_config;
        if (is_array($transportConfig)) {
            $sensitiveKeys = ['key', 'secret', 'token', 'password'];
            foreach (array_keys($transportConfig) as $configKey) {
                foreach ($sensitiveKeys as $sensitive) {
                    if (stripos($configKey, $sensitive) !== false) {
                        unset($transportConfig[$configKey]);
                        break;
                    }
                }
            }
        }

        return Response::text(json_encode([
            'id' => $tool->id,
            'name' => $tool->name,
            'slug' => $tool->slug,
            'type' => $tool->type->value,
            'status' => $tool->status->value,
            'description' => $tool->description,
            'transport_config' => $transportConfig,
            'created_at' => $tool->created_at?->toIso8601String(),
        ]));
    }
}
