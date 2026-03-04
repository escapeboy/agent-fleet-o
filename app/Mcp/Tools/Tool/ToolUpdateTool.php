<?php

namespace App\Mcp\Tools\Tool;

use App\Domain\Tool\Actions\UpdateToolAction;
use App\Domain\Tool\Enums\ToolRiskLevel;
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
            'risk_level' => $schema->string()
                ->description('Risk classification: safe, read, write, destructive')
                ->enum(['safe', 'read', 'write', 'destructive']),
            'credential_id' => $schema->string()
                ->description('UUID of a linked Credential (set to link; omit to leave unchanged)'),
            'clear_credential_id' => $schema->boolean()
                ->description('Set true to remove the linked credential from this tool'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'tool_id' => 'required|string',
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|string|in:active,disabled',
            'risk_level' => 'nullable|string|in:safe,read,write,destructive',
            'credential_id' => 'nullable|uuid|exists:credentials,id',
            'clear_credential_id' => 'nullable|boolean',
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
                riskLevel: isset($validated['risk_level']) ? ToolRiskLevel::from($validated['risk_level']) : null,
                credentialId: $validated['credential_id'] ?? null,
                clearCredentialId: (bool) ($validated['clear_credential_id'] ?? false),
            );

            return Response::text(json_encode([
                'success' => true,
                'tool_id' => $result->id,
                'name' => $result->name,
                'status' => $result->status->value,
                'risk_level' => $result->risk_level?->value,
                'updated_fields' => array_keys(array_filter([
                    'name' => $validated['name'] ?? null,
                    'description' => $validated['description'] ?? null,
                    'status' => $validated['status'] ?? null,
                    'risk_level' => $validated['risk_level'] ?? null,
                ], fn ($v) => $v !== null)),
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
