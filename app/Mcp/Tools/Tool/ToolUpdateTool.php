<?php

namespace App\Mcp\Tools\Tool;

use App\Domain\Tool\Actions\UpdateToolAction;
use App\Domain\Tool\Enums\ToolRiskLevel;
use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Models\Tool as ToolModel;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
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
            'network_policy' => $schema->string()
                ->description('JSON string defining egress rules for Docker sandbox (built_in bash only). Example: {"rules":[{"protocol":"tcp","host":"api.example.com","port":443}],"default_action":"deny"}. Pass "null" to clear.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }

        $validated = $request->validate([
            'tool_id' => 'required|string',
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'status' => 'nullable|string|in:active,disabled',
            'risk_level' => 'nullable|string|in:safe,read,write,destructive',
            'credential_id' => ['nullable', 'uuid',
                Rule::exists('credentials', 'id')->where('team_id', $teamId)],
            'clear_credential_id' => 'nullable|boolean',
            'network_policy' => 'nullable|string',
        ]);

        $tool = ToolModel::withoutGlobalScopes()->where('team_id', $teamId)->find($validated['tool_id']);

        if (! $tool) {
            return Response::error('Tool not found.');
        }

        // Parse optional network_policy JSON string
        $networkPolicy = null;
        $clearNetworkPolicy = false;
        if (isset($validated['network_policy'])) {
            if ($validated['network_policy'] === 'null') {
                $clearNetworkPolicy = true;
            } else {
                $networkPolicy = json_decode($validated['network_policy'], true);
                if (! is_array($networkPolicy)) {
                    return Response::error('network_policy must be a valid JSON object or "null" to clear.');
                }
            }
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

            // Apply network_policy update separately (not handled by UpdateToolAction yet)
            if ($networkPolicy !== null) {
                $result->update(['network_policy' => $networkPolicy]);
                $result = $result->fresh();
            } elseif ($clearNetworkPolicy) {
                $result->update(['network_policy' => null]);
                $result = $result->fresh();
            }

            $updatedFields = array_keys(array_filter([
                'name' => $validated['name'] ?? null,
                'description' => $validated['description'] ?? null,
                'status' => $validated['status'] ?? null,
                'risk_level' => $validated['risk_level'] ?? null,
                'credential_id' => $validated['credential_id'] ?? null,
                'network_policy' => $networkPolicy !== null ? true : ($clearNetworkPolicy ? true : null),
            ], fn ($v) => $v !== null));

            if (! empty($validated['clear_credential_id'])) {
                $updatedFields[] = 'credential_id';
            }

            return Response::text(json_encode([
                'success' => true,
                'tool_id' => $result->id,
                'name' => $result->name,
                'status' => $result->status->value,
                'risk_level' => $result->risk_level?->value,
                'credential_id' => $result->credential_id,
                'updated_fields' => array_values(array_unique($updatedFields)),
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
