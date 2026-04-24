<?php

namespace App\Mcp\Tools\Codemode;

use App\Mcp\Concerns\HasStructuredErrors;
use App\Mcp\Services\ToolRegistry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchema as JsonSchemaFactory;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Code Mode search — returns a compact list of tools matching a free-text
 * query. Lets an agent discover the right tool without loading every tool
 * schema up front. Pair with fleetq_codemode_execute to invoke the chosen
 * tool with its arguments.
 */
#[IsReadOnly]
#[IsIdempotent]
class CodemodeSearchTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'fleetq_codemode_search';

    protected string $description = 'Search the FleetQ MCP tool registry by free-text query. Returns the top matching tools with compact metadata (name, description, input schema) so you can discover the right tool without loading every schema up front. Use this before fleetq_codemode_execute.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Free-text query matched against tool names and descriptions. Example: "list agents" or "create workflow".')
                ->required(),
            'limit' => $schema->number()
                ->description('Maximum number of tools to return (default 10, max 50).'),
        ];
    }

    public function handle(Request $request, ToolRegistry $registry): Response
    {
        $query = trim((string) $request->get('query', ''));
        if ($query === '') {
            return $this->invalidArgumentError('query is required and must be a non-empty string');
        }

        $limit = (int) $request->get('limit', 10);
        if ($limit < 1) {
            $limit = 10;
        }
        if ($limit > 50) {
            $limit = 50;
        }

        $matches = $registry->search($query, $limit);

        $results = [];
        foreach ($matches as $tool) {
            $results[] = [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'inputSchema' => $this->extractInputSchema($tool),
            ];
        }

        return Response::text(json_encode([
            'query' => $query,
            'count' => count($results),
            'tools' => $results,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array<string, mixed>
     */
    protected function extractInputSchema(Tool $tool): array
    {
        $schema = JsonSchemaFactory::object($tool->schema(...))->toArray();
        $schema['properties'] ??= (object) [];

        return $schema;
    }
}
