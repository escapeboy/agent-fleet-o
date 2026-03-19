<?php

namespace App\Mcp\Tools\Boruna;

use App\Domain\Tool\Models\Tool;
use App\Domain\Tool\Services\McpStdioClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool as McpTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Validate a Boruna .ax script for syntax errors without executing it.
 */
#[IsReadOnly]
class BorunaValidateTool extends McpTool
{
    protected string $name = 'boruna_validate';

    protected string $description = 'Validate a Boruna .ax script for syntax and semantic errors without executing it. Returns parse errors, undefined variables, capability violations, and lint warnings.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'script' => $schema->string()
                ->description('The .ax script source code to validate')
                ->required(),
            'boruna_tool_id' => $schema->string()
                ->description('UUID of the mcp_stdio Tool pointing to the Boruna binary. Auto-detected if omitted.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'script'         => 'required|string',
            'boruna_tool_id' => 'nullable|uuid',
        ]);

        $teamId = auth()->user()->current_team_id;
        $tool = $this->resolveTool($teamId, $validated['boruna_tool_id'] ?? null);

        if (! $tool) {
            return Response::error('No active Boruna tool found. Create an mcp_stdio Tool pointing to the Boruna binary.');
        }

        try {
            $output = app(McpStdioClient::class)->callTool($tool, 'boruna_validate', [
                'script' => $validated['script'],
            ]);

            return Response::text(json_encode([
                'valid'   => ! str_contains(strtolower($output), 'error'),
                'output'  => $output,
            ]));
        } catch (\Throwable $e) {
            return Response::error("Boruna validate failed: {$e->getMessage()}");
        }
    }

    private function resolveTool(string $teamId, ?string $toolId): ?Tool
    {
        if ($toolId) {
            return Tool::where('id', $toolId)->where('team_id', $teamId)->where('type', 'mcp_stdio')->first();
        }

        return Tool::where('team_id', $teamId)
            ->where('type', 'mcp_stdio')
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereRaw("transport_config->>'command' ILIKE '%boruna%'")
                    ->orWhereRaw("transport_config->>'command' ILIKE '%boruna-mcp%'");
            })
            ->first();
    }
}
