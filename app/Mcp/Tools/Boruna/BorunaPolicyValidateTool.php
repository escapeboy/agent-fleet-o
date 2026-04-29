<?php

namespace App\Mcp\Tools\Boruna;

use App\Domain\Tool\Models\Tool;
use App\Domain\Tool\Services\McpStdioClient;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool as McpTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Validate a Boruna capability policy (v0.4.0+ strict validator).
 *
 * Useful as a CI gate or pre-save check in the skill form. Uses the same
 * strict validator as the upstream boruna CLI `boruna policy validate` command.
 */
#[IsReadOnly]
class BorunaPolicyValidateTool extends McpTool
{
    use HasStructuredErrors;

    protected string $name = 'boruna_policy_validate';

    protected string $description = 'Validate a Boruna capability policy JSON against the strict schema (v0.4.0+). Checks: required default_allow bool, canonical capability names in rules keys (no aliases), unknown fields rejected, valid net_policy shape. Returns valid/invalid + list of errors. Use before saving a structured Policy to a boruna_script skill.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'policy_json' => $schema->string()
                ->description('JSON-encoded Boruna policy object to validate. Must have a top-level default_allow boolean. Example: {"default_allow":false,"rules":{"net.fetch":{"allow":true}}}')
                ->required(),
            'boruna_tool_id' => $schema->string()
                ->description('UUID of the mcp_stdio Tool pointing to the Boruna binary. Auto-detected if omitted.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'policy_json' => 'required|string',
            'boruna_tool_id' => 'nullable|uuid',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        $tool = $this->resolveTool($teamId, $validated['boruna_tool_id'] ?? null);

        if (! $tool) {
            return $this->failedPreconditionError('No active Boruna tool found. Create an mcp_stdio Tool pointing to the Boruna binary.');
        }

        // Sanity-check that the input is valid JSON before sending to the binary.
        if (json_decode($validated['policy_json']) === null && json_last_error() !== JSON_ERROR_NONE) {
            return $this->invalidArgumentError('policy_json is not valid JSON: '.json_last_error_msg());
        }

        try {
            $output = app(McpStdioClient::class)->callTool($tool, 'boruna_policy_validate', [
                'policy_json' => $validated['policy_json'],
            ]);

            $parsed = json_decode($output, true);

            return Response::text(json_encode([
                'valid' => ($parsed['valid'] ?? false) === true,
                'errors' => $parsed['errors'] ?? [],
                'output' => $output,
            ]));
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    private function resolveTool(string $teamId, ?string $toolId): ?Tool
    {
        if ($toolId) {
            return Tool::where('id', $toolId)
                ->where('team_id', $teamId)
                ->where('type', 'mcp_stdio')
                ->where('status', 'active')
                ->first();
        }

        return Tool::where('team_id', $teamId)
            ->where('type', 'mcp_stdio')
            ->where('status', 'active')
            ->where('subkind', 'boruna')
            ->first();
    }
}
