<?php

namespace App\Domain\Workflow\Actions;

use App\Domain\Workflow\Models\Workflow;
use Illuminate\Validation\ValidationException;

/**
 * Exposes a workflow as a named MCP gateway tool.
 */
class EnableWorkflowMcpGatewayAction
{
    public const TOOL_NAME_PATTERN = '/^[a-z][a-z0-9_]{2,63}$/';

    /**
     * @throws ValidationException
     */
    public function execute(Workflow $workflow, string $toolName, string $mode = 'async'): Workflow
    {
        if (! preg_match(self::TOOL_NAME_PATTERN, $toolName)) {
            throw ValidationException::withMessages([
                'tool_name' => 'The tool name must be snake_case, start with a letter and be 3-64 characters long.',
            ]);
        }

        if (! in_array($mode, ['sync', 'async'], true)) {
            throw ValidationException::withMessages([
                'mcp_execution_mode' => 'The execution mode must be sync or async.',
            ]);
        }

        // The mcp_tool_name column has a global unique index, so check across all teams.
        $conflict = Workflow::withoutGlobalScopes()
            ->where('mcp_tool_name', $toolName)
            ->where('id', '!=', $workflow->getKey())
            ->exists();

        if ($conflict) {
            throw ValidationException::withMessages([
                'tool_name' => "The tool name '{$toolName}' is already in use by another workflow. Choose a different name.",
            ]);
        }

        $workflow->update([
            'mcp_exposed' => true,
            'mcp_tool_name' => $toolName,
            'mcp_execution_mode' => $mode,
        ]);

        return $workflow;
    }
}
