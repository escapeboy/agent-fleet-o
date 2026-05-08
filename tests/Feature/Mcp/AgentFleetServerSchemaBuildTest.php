<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\AgentFleetServer;
use App\Mcp\Servers\CompactMcpServer;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use ReflectionClass;
use Tests\TestCase;
use Throwable;

/**
 * Guards `/mcp/full` and `/mcp` against framework-level JsonSchema API drift.
 *
 * Eager schema build is what `tools/list` does at the MCP layer — a single tool whose
 * `schema()` body uses an unsupported method (e.g. `->minimum()` after Laravel 13's
 * JsonSchema migrated to `->min()`) crashes the entire listing for every client.
 *
 * This test instantiates each registered tool, calls `schema()` with the real JsonSchema
 * factory, and asserts no `Throwable` propagates. We stop at the first failure with the
 * tool class name in the message so future regressions point at the offending file.
 */
class AgentFleetServerSchemaBuildTest extends TestCase
{
    public function test_full_server_tool_schemas_build_without_throwing(): void
    {
        $this->assertSchemaBuildsForServer(AgentFleetServer::class);
    }

    public function test_compact_server_tool_schemas_build_without_throwing(): void
    {
        $this->assertSchemaBuildsForServer(CompactMcpServer::class);
    }

    private function assertSchemaBuildsForServer(string $serverClass): void
    {
        $reflection = new ReflectionClass($serverClass);
        $tools = $reflection->getProperty('tools')->getDefaultValue();

        $this->assertIsArray($tools);
        $this->assertNotEmpty($tools, "{$serverClass} registered no tools.");

        $jsonSchema = new JsonSchemaTypeFactory;

        foreach ($tools as $toolClass) {
            try {
                $tool = app($toolClass);
                // Most tools accept a JsonSchema parameter; a handful (umbrella tools without
                // input args) define schema() without parameters. Reflect-and-call so both
                // shapes succeed.
                $method = (new ReflectionClass($tool))->getMethod('schema');
                $args = $method->getNumberOfParameters() > 0 ? [$jsonSchema] : [];
                $schema = $method->invoke($tool, ...$args);

                $this->assertIsArray(
                    $schema,
                    "{$toolClass}::schema() must return an array, got ".get_debug_type($schema),
                );
            } catch (Throwable $e) {
                $this->fail(sprintf(
                    "Tool %s failed schema build: %s\n%s",
                    $toolClass,
                    $e->getMessage(),
                    $e->getTraceAsString(),
                ));
            }
        }
    }
}
