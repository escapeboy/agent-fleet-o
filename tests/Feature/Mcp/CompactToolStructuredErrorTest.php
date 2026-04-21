<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\ErrorCode;
use App\Mcp\Exceptions\DeadlineExceededException;
use App\Mcp\Tools\Compact\CompactTool;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use RuntimeException;
use Tests\TestCase;

class CompactToolStructuredErrorTest extends TestCase
{
    public function test_missing_action_returns_structured_invalid_argument(): void
    {
        $tool = new FakeCompactTool;
        $response = $tool->handle(new Request([]));

        $payload = $this->decode($response);

        $this->assertSame(ErrorCode::InvalidArgument->value, $payload['error']['code']);
        $this->assertFalse($payload['error']['retryable']);
        $this->assertStringContainsString("'action'", $payload['error']['message']);
    }

    public function test_unknown_action_returns_structured_invalid_argument(): void
    {
        $tool = new FakeCompactTool;
        $response = $tool->handle(new Request(['action' => 'nope']));

        $payload = $this->decode($response);

        $this->assertSame(ErrorCode::InvalidArgument->value, $payload['error']['code']);
    }

    public function test_tool_throwing_authorization_returns_permission_denied(): void
    {
        FakeThrowingTool::$exception = new AuthorizationException('denied');
        $tool = new FakeCompactTool;

        $response = $tool->handle(new Request(['action' => 'throw']));

        $payload = $this->decode($response);

        $this->assertSame(ErrorCode::PermissionDenied->value, $payload['error']['code']);
        $this->assertFalse($payload['error']['retryable']);
    }

    public function test_tool_throwing_model_not_found_returns_not_found(): void
    {
        FakeThrowingTool::$exception = new ModelNotFoundException;
        $tool = new FakeCompactTool;

        $response = $tool->handle(new Request(['action' => 'throw']));

        $payload = $this->decode($response);

        $this->assertSame(ErrorCode::NotFound->value, $payload['error']['code']);
    }

    public function test_tool_throwing_deadline_exceeded_returns_deadline_exceeded(): void
    {
        FakeThrowingTool::$exception = DeadlineExceededException::afterMs(200, 100);
        $tool = new FakeCompactTool;

        $response = $tool->handle(new Request(['action' => 'throw']));

        $payload = $this->decode($response);

        $this->assertSame(ErrorCode::DeadlineExceeded->value, $payload['error']['code']);
    }

    public function test_unknown_exception_falls_back_to_internal_with_safe_message(): void
    {
        FakeThrowingTool::$exception = new RuntimeException('sensitive internal detail');
        $tool = new FakeCompactTool;

        $response = $tool->handle(new Request(['action' => 'throw']));

        $payload = $this->decode($response);

        $this->assertSame(ErrorCode::Internal->value, $payload['error']['code']);
        $this->assertStringNotContainsString('sensitive internal detail', $payload['error']['message']);
    }

    public function test_success_passes_through_unmodified(): void
    {
        FakeThrowingTool::$exception = null;
        FakeReturnTool::$returns = 'ok';
        $tool = new FakeCompactTool;

        $response = $tool->handle(new Request(['action' => 'ok']));

        $this->assertFalse($response->isError());
    }

    /**
     * @return array{error: array{code: string, message: string, retryable: bool}}
     */
    private function decode(Response $response): array
    {
        $this->assertTrue($response->isError(), 'Expected error response');

        $content = $response->content();
        $reflection = new \ReflectionObject($content);

        if ($reflection->hasMethod('text')) {
            $text = $content->text();
        } else {
            $prop = $reflection->getProperty('text');
            $prop->setAccessible(true);
            $text = $prop->getValue($content);
        }

        $decoded = json_decode($text, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('error', $decoded);

        return $decoded;
    }
}

class FakeCompactTool extends CompactTool
{
    protected string $name = 'fake_compact';

    protected string $description = 'Test-only fake compact tool';

    protected function toolMap(): array
    {
        return [
            'throw' => FakeThrowingTool::class,
            'ok' => FakeReturnTool::class,
        ];
    }
}

class FakeThrowingTool extends Tool
{
    public static ?\Throwable $exception = null;

    protected string $name = 'fake_throwing';

    protected string $description = 'Test-only throwing tool';

    public function handle(Request $request): Response
    {
        if (self::$exception) {
            throw self::$exception;
        }

        return Response::text('ok');
    }
}

class FakeReturnTool extends Tool
{
    public static string $returns = 'ok';

    protected string $name = 'fake_return';

    protected string $description = 'Test-only returning tool';

    public function handle(Request $request): Response
    {
        return Response::text(self::$returns);
    }
}
