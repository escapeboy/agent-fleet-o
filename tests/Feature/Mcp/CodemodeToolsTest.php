<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\ErrorCode;
use App\Mcp\Services\ToolRegistry;
use App\Mcp\Tools\Codemode\CodemodeExecuteTool;
use App\Mcp\Tools\Codemode\CodemodeSearchTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Tests\TestCase;

class CodemodeToolsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Replace the registry with a stub that holds our fake tools so tests
        // don't depend on the full 500-tool AgentFleetServer registration.
        $registry = new FakeToolRegistry([
            new FakeAgentListTool,
            new FakeWorkflowCreateTool,
            new FakeSignalIngestTool,
        ]);
        $this->app->instance(ToolRegistry::class, $registry);
    }

    // ── Search ────────────────────────────────────────────────────────────────

    public function test_search_returns_compact_metadata(): void
    {
        /** @var CodemodeSearchTool $tool */
        $tool = $this->app->make(CodemodeSearchTool::class);

        $response = $this->invoke($tool, ['query' => 'agent']);
        $payload = $this->decodeText($response);

        $this->assertSame('agent', $payload['query']);
        $this->assertSame(1, $payload['count']);
        $this->assertSame('fake_agent_list', $payload['tools'][0]['name']);
        $this->assertArrayHasKey('description', $payload['tools'][0]);
        $this->assertArrayHasKey('inputSchema', $payload['tools'][0]);
    }

    public function test_search_requires_non_empty_query(): void
    {
        /** @var CodemodeSearchTool $tool */
        $tool = $this->app->make(CodemodeSearchTool::class);

        $response = $this->invoke($tool, ['query' => '  ']);
        $payload = $this->decodeError($response);

        $this->assertSame(ErrorCode::InvalidArgument->value, $payload['error']['code']);
    }

    public function test_search_clamps_limit(): void
    {
        /** @var CodemodeSearchTool $tool */
        $tool = $this->app->make(CodemodeSearchTool::class);

        $response = $this->invoke($tool, ['query' => 'fake', 'limit' => 999]);
        $payload = $this->decodeText($response);

        // We have 3 fake tools; all match "fake" via description.
        $this->assertSame(3, $payload['count']);
    }

    public function test_search_ranks_name_matches_above_description_only(): void
    {
        /** @var CodemodeSearchTool $tool */
        $tool = $this->app->make(CodemodeSearchTool::class);

        $response = $this->invoke($tool, ['query' => 'workflow']);
        $payload = $this->decodeText($response);

        // fake_workflow_create has "workflow" in name (score 10) AND description (score 1)
        // No other tool has "workflow" anywhere — so we expect 1 result.
        $this->assertSame(1, $payload['count']);
        $this->assertSame('fake_workflow_create', $payload['tools'][0]['name']);
    }

    // ── Execute ───────────────────────────────────────────────────────────────

    public function test_execute_dispatches_to_named_tool(): void
    {
        /** @var CodemodeExecuteTool $tool */
        $tool = $this->app->make(CodemodeExecuteTool::class);

        $response = $this->invoke($tool, [
            'tool' => 'fake_agent_list',
            'arguments' => ['limit' => 5],
        ]);

        $payload = $this->decodeText($response);
        $this->assertSame('fake_agent_list invoked', $payload['echo']);
        $this->assertSame(['limit' => 5], $payload['arguments']);
    }

    public function test_execute_rejects_missing_tool_name(): void
    {
        /** @var CodemodeExecuteTool $tool */
        $tool = $this->app->make(CodemodeExecuteTool::class);

        $response = $this->invoke($tool, ['tool' => '']);
        $payload = $this->decodeError($response);

        $this->assertSame(ErrorCode::InvalidArgument->value, $payload['error']['code']);
    }

    public function test_execute_returns_not_found_for_unknown_tool(): void
    {
        /** @var CodemodeExecuteTool $tool */
        $tool = $this->app->make(CodemodeExecuteTool::class);

        $response = $this->invoke($tool, ['tool' => 'no_such_tool']);
        $payload = $this->decodeError($response);

        $this->assertSame(ErrorCode::NotFound->value, $payload['error']['code']);
    }

    public function test_execute_refuses_recursion_into_codemode(): void
    {
        /** @var CodemodeExecuteTool $tool */
        $tool = $this->app->make(CodemodeExecuteTool::class);

        foreach (['fleetq_codemode_search', 'fleetq_codemode_execute'] as $name) {
            $response = $this->invoke($tool, ['tool' => $name]);
            $payload = $this->decodeError($response);

            $this->assertSame(ErrorCode::InvalidArgument->value, $payload['error']['code']);
        }
    }

    public function test_execute_rejects_non_object_arguments(): void
    {
        /** @var CodemodeExecuteTool $tool */
        $tool = $this->app->make(CodemodeExecuteTool::class);

        $response = $this->invoke($tool, [
            'tool' => 'fake_agent_list',
            'arguments' => 'not-an-object',
        ]);
        $payload = $this->decodeError($response);

        $this->assertSame(ErrorCode::InvalidArgument->value, $payload['error']['code']);
    }

    public function test_execute_passes_arguments_not_parent_params_to_child(): void
    {
        /** @var CodemodeExecuteTool $tool */
        $tool = $this->app->make(CodemodeExecuteTool::class);

        // Parent params include `tool` and `arguments`. Child must only see
        // the flat `arguments` payload.
        $response = $this->invoke($tool, [
            'tool' => 'fake_agent_list',
            'arguments' => ['status' => 'active'],
        ]);

        $payload = $this->decodeText($response);
        $this->assertSame(['status' => 'active'], $payload['arguments']);
        $this->assertArrayNotHasKey('tool', $payload['arguments']);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    protected function invoke(Tool $tool, array $args): Response
    {
        return $this->app->call([$tool, 'handle'], ['request' => new Request($args)]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodeText(Response $response): array
    {
        $content = $response->content();
        $raw = (string) $content;

        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodeError(Response $response): array
    {
        $content = $response->content();
        $raw = (string) $content;
        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('error', $decoded);

        return $decoded;
    }
}

// ── Fakes ─────────────────────────────────────────────────────────────────────

class FakeToolRegistry extends ToolRegistry
{
    /** @var array<string, Tool> */
    protected array $fakeTools;

    /**
     * @param  array<int, Tool>  $tools
     */
    public function __construct(array $tools)
    {
        $map = [];
        foreach ($tools as $t) {
            $map[$t->name()] = $t;
        }
        $this->fakeTools = $map;
    }

    public function all(): array
    {
        return $this->fakeTools;
    }
}

#[IsReadOnly]
class FakeAgentListTool extends Tool
{
    protected string $name = 'fake_agent_list';

    protected string $description = 'List fake agents.';

    public function schema(JsonSchema $schema): array
    {
        return ['limit' => $schema->number()];
    }

    public function handle(Request $request): Response
    {
        return Response::text(json_encode([
            'echo' => 'fake_agent_list invoked',
            'arguments' => $request->toArray(),
        ]));
    }
}

#[IsReadOnly]
class FakeWorkflowCreateTool extends Tool
{
    protected string $name = 'fake_workflow_create';

    protected string $description = 'Create a fake workflow.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        return Response::text('ok');
    }
}

#[IsReadOnly]
class FakeSignalIngestTool extends Tool
{
    protected string $name = 'fake_signal_ingest';

    protected string $description = 'Ingest a fake signal.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        return Response::text('ok');
    }
}
