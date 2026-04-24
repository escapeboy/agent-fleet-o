<?php

namespace App\Mcp\Tools\Codemode;

use App\Mcp\Concerns\HasStructuredErrors;
use App\Mcp\ErrorCode;
use App\Mcp\Services\ToolRegistry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

/**
 * Code Mode execute — invoke any FleetQ MCP tool by name with the given
 * arguments. Lets an agent call tools discovered via fleetq_codemode_search
 * without loading every tool schema up front.
 *
 * All permissions, team scoping, and audit trails apply as if the tool were
 * called directly. Every tool's shouldRegister() gate runs via ToolRegistry;
 * tools hidden from the current team are not reachable here.
 *
 * Annotated destructive because the wrapped tool may be destructive; clients
 * should treat this tool as the most permissive of any tool it may dispatch.
 */
#[IsDestructive]
class CodemodeExecuteTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'fleetq_codemode_execute';

    protected string $description = 'Invoke any FleetQ MCP tool by name with the given arguments. Use fleetq_codemode_search first to discover tools. Team scoping, role gating, and audit trails apply as if the tool were called directly.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'tool' => $schema->string()
                ->description('Name of the tool to invoke (e.g. "agent_list", "experiment_get").')
                ->required(),
            'arguments' => $schema->object()
                ->description('Arguments passed to the tool. Shape matches the tool\'s inputSchema from fleetq_codemode_search.'),
        ];
    }

    public function handle(Request $request, ToolRegistry $registry): Response
    {
        $name = trim((string) $request->get('tool', ''));
        if ($name === '') {
            return $this->invalidArgumentError('tool is required and must be a non-empty string');
        }

        // Refuse to dispatch Code Mode tools via Code Mode to prevent recursion
        // and the schema-inflation defeat that Code Mode exists to solve.
        if (in_array($name, ['fleetq_codemode_search', 'fleetq_codemode_execute'], true)) {
            return $this->errorResponse(
                ErrorCode::InvalidArgument,
                "Cannot dispatch '{$name}' via fleetq_codemode_execute.",
            );
        }

        $tool = $registry->find($name);
        if ($tool === null) {
            return $this->notFoundError('tool', $name);
        }

        $arguments = $request->get('arguments', []);
        if ($arguments === null) {
            $arguments = [];
        }
        if (! is_array($arguments)) {
            return $this->invalidArgumentError('arguments must be an object');
        }

        $childRequest = new Request($arguments);

        return app()->call([$tool, 'handle'], ['request' => $childRequest]);
    }
}
