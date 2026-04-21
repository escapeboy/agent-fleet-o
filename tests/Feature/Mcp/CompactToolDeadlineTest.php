<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\DeadlineContext;
use App\Mcp\ErrorCode;
use App\Mcp\Tools\Compact\CompactTool;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Tests\TestCase;

class CompactToolDeadlineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app(DeadlineContext::class)->clear();
    }

    public function test_deadline_exceeded_during_tool_returns_structured_error(): void
    {
        $tool = new DeadlineCompactTool;

        $response = $tool->handle(new Request([
            'action' => 'slow',
            'deadline_ms' => 100,
        ]));

        $payload = $this->decode($response);

        $this->assertSame(ErrorCode::DeadlineExceeded->value, $payload['error']['code']);
        $this->assertFalse($payload['error']['retryable']);
    }

    public function test_no_deadline_param_does_not_enforce(): void
    {
        $tool = new DeadlineCompactTool;

        $response = $tool->handle(new Request(['action' => 'slow']));

        $this->assertFalse($response->isError());
    }

    public function test_deadline_context_cleared_after_call(): void
    {
        $tool = new DeadlineCompactTool;
        $tool->handle(new Request(['action' => 'fast', 'deadline_ms' => 1000]));

        $this->assertFalse(app(DeadlineContext::class)->isSet());
    }

    public function test_nested_calls_inherit_parent_deadline(): void
    {
        // Pre-set the deadline (simulate nested call)
        app(DeadlineContext::class)->set(100);

        $tool = new DeadlineCompactTool;

        // Nested call with its own deadline param should NOT override the active deadline
        $response = $tool->handle(new Request([
            'action' => 'slow',
            'deadline_ms' => 10_000, // would allow completion if it overrode
        ]));

        $payload = $this->decode($response);

        $this->assertSame(ErrorCode::DeadlineExceeded->value, $payload['error']['code']);

        app(DeadlineContext::class)->clear();
    }

    /**
     * @return array{error: array{code: string, retryable: bool, message: string}}
     */
    private function decode(Response $response): array
    {
        $this->assertTrue($response->isError(), 'Expected error response');

        $content = $response->content();
        $reflection = new \ReflectionObject($content);
        $prop = $reflection->getProperty('text');
        $prop->setAccessible(true);
        $text = $prop->getValue($content);

        $decoded = json_decode($text, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('error', $decoded);

        return $decoded;
    }
}

class DeadlineCompactTool extends CompactTool
{
    protected string $name = 'deadline_compact';

    protected string $description = 'Test-only compact tool for deadline propagation';

    protected function toolMap(): array
    {
        return [
            'slow' => SlowInnerTool::class,
            'fast' => FastInnerTool::class,
        ];
    }
}

class SlowInnerTool extends Tool
{
    protected string $name = 'slow_inner';

    protected string $description = 'Sleeps 250ms then checks deadline';

    public function handle(Request $request): Response
    {
        usleep(250_000);
        app(DeadlineContext::class)->assertNotExpired();

        return Response::text('done');
    }
}

class FastInnerTool extends Tool
{
    protected string $name = 'fast_inner';

    protected string $description = 'Returns immediately';

    public function handle(Request $request): Response
    {
        return Response::text('done');
    }
}
