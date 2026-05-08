<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\AgentFleetServer;
use App\Mcp\Servers\CompactMcpServer;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use ReflectionClass;
use Tests\TestCase;
use Throwable;

/**
 * Guards `/mcp/full` and `/mcp` against MCP-layer regressions that crash
 * `tools/list` at runtime:
 *
 *   1. JsonSchema API drift — e.g. `->minimum()` after Laravel 13's migration
 *      to `->min()`. Caught by `schema()` invocation.
 *
 *   2. Attribute namespace typos — e.g. `Laravel\Mcp\Server\Attributes\IsReadOnly`
 *      (does not exist) instead of `Laravel\Mcp\Server\Tools\Annotations\IsReadOnly`.
 *      ReflectionClass::getAttributes() with ReflectionAttribute::IS_INSTANCEOF
 *      flag throws an `Error` if the referenced class is missing — same path
 *      the MCP server walks during tool registration. Plain instantiation
 *      does NOT trigger attribute resolution; an explicit getAttributes()
 *      call is required.
 *
 * One bad tool stops the entire listing for every client, so this test fails
 * fast with the offending class name.
 */
class AgentFleetServerSchemaBuildTest extends TestCase
{
    public function test_full_server_tool_schemas_build_without_throwing(): void
    {
        $this->assertToolsLoadCleanlyForServer(AgentFleetServer::class);
    }

    public function test_compact_server_tool_schemas_build_without_throwing(): void
    {
        $this->assertToolsLoadCleanlyForServer(CompactMcpServer::class);
    }

    /**
     * @param  class-string  $serverClass
     */
    protected function assertToolsLoadCleanlyForServer(string $serverClass): void
    {
        $reflection = new ReflectionClass($serverClass);
        $tools = $reflection->getProperty('tools')->getDefaultValue();

        $this->assertIsArray($tools);
        $this->assertNotEmpty($tools, "{$serverClass} registered no tools.");

        $jsonSchema = new JsonSchemaTypeFactory;

        foreach ($tools as $toolClass) {
            try {
                $tool = app($toolClass);
                $toolReflection = new ReflectionClass($tool);

                // (2) Force attribute INSTANTIATION. ReflectionAttribute->getName()
                // returns the class name as a string without loading the class;
                // ->newInstance() actually instantiates it, throwing "Attribute class
                // ... not found" if the use-statement points at a non-existent
                // namespace. This is the same path Laravel\Mcp's HasAnnotations
                // trait walks at registration time, so a typo'd #[IsReadOnly]
                // surfaces here instead of in production.
                foreach ($toolReflection->getAttributes() as $attribute) {
                    $attribute->newInstance();
                }

                // (1) Build the schema. Most tools accept a JsonSchema parameter;
                // a few umbrella tools omit it. Reflect-and-call so both shapes work.
                $method = $toolReflection->getMethod('schema');
                $args = $method->getNumberOfParameters() > 0 ? [$jsonSchema] : [];
                $schema = $method->invoke($tool, ...$args);

                $this->assertIsArray(
                    $schema,
                    "{$toolClass}::schema() must return an array, got ".get_debug_type($schema),
                );
            } catch (Throwable $e) {
                $this->fail(sprintf(
                    "Tool %s failed to load: %s\n%s",
                    $toolClass,
                    $e->getMessage(),
                    $e->getTraceAsString(),
                ));
            }
        }
    }
}
