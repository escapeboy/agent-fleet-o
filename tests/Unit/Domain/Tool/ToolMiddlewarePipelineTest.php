<?php

namespace Tests\Unit\Domain\Tool;

use App\Domain\Tool\Contracts\ToolExecutionMiddlewareInterface;
use App\Domain\Tool\DTOs\ToolExecutionContext;
use App\Domain\Tool\Models\Tool;
use Closure;
use Mockery;
use PHPUnit\Framework\TestCase;

class ToolMiddlewarePipelineTest extends TestCase
{
    public function test_pipeline_executes_handler_when_no_middleware(): void
    {
        $handler = fn (ToolExecutionContext $ctx) => ['result' => 'ok'];

        $pipeline = $this->buildPipeline([], $handler);
        $context = $this->makeContext();

        $result = $pipeline($context);

        $this->assertEquals(['result' => 'ok'], $result);
    }

    public function test_middleware_wraps_handler(): void
    {
        $middleware = new class implements ToolExecutionMiddlewareInterface
        {
            public function handle(ToolExecutionContext $context, Closure $next): array
            {
                $result = $next($context);
                $result['wrapped'] = true;

                return $result;
            }
        };

        $handler = fn (ToolExecutionContext $ctx) => ['result' => 'ok'];
        $pipeline = $this->buildPipeline([$middleware], $handler);

        $result = $pipeline($this->makeContext());

        $this->assertEquals(['result' => 'ok', 'wrapped' => true], $result);
    }

    public function test_middleware_can_short_circuit(): void
    {
        $blocker = new class implements ToolExecutionMiddlewareInterface
        {
            public function handle(ToolExecutionContext $context, Closure $next): array
            {
                return ['error' => 'blocked'];
            }
        };

        $handler = fn (ToolExecutionContext $ctx) => ['result' => 'should not reach'];
        $pipeline = $this->buildPipeline([$blocker], $handler);

        $result = $pipeline($this->makeContext());

        $this->assertEquals(['error' => 'blocked'], $result);
    }

    public function test_middleware_executes_in_order(): void
    {
        $order = [];

        $first = new class($order) implements ToolExecutionMiddlewareInterface
        {
            public function __construct(private array &$order) {}

            public function handle(ToolExecutionContext $context, Closure $next): array
            {
                $this->order[] = 'first-before';
                $result = $next($context);
                $this->order[] = 'first-after';

                return $result;
            }
        };

        $second = new class($order) implements ToolExecutionMiddlewareInterface
        {
            public function __construct(private array &$order) {}

            public function handle(ToolExecutionContext $context, Closure $next): array
            {
                $this->order[] = 'second-before';
                $result = $next($context);
                $this->order[] = 'second-after';

                return $result;
            }
        };

        $handler = function (ToolExecutionContext $ctx) use (&$order) {
            $order[] = 'handler';

            return ['done' => true];
        };

        $pipeline = $this->buildPipeline([$first, $second], $handler);
        $pipeline($this->makeContext());

        $this->assertEquals(['first-before', 'second-before', 'handler', 'second-after', 'first-after'], $order);
    }

    /**
     * Replicate the same build pattern as ToolMiddlewarePipeline::buildPipeline().
     */
    private function buildPipeline(array $middleware, Closure $handler): Closure
    {
        return array_reduce(
            array_reverse($middleware),
            fn (Closure $next, ToolExecutionMiddlewareInterface $mw) => fn (ToolExecutionContext $ctx) => $mw->handle($ctx, $next),
            $handler,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeContext(): ToolExecutionContext
    {
        $toolMock = Mockery::mock(Tool::class);

        return new ToolExecutionContext(
            tool: $toolMock,
            toolName: 'test_tool',
            input: ['key' => 'value'],
            agent: null,
            teamId: 'team-123',
        );
    }
}
