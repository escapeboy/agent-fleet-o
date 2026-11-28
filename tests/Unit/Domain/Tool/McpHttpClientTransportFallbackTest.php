<?php

namespace Tests\Unit\Domain\Tool;

use App\Domain\Tool\Services\McpHttpClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class McpHttpClientTransportFallbackTest extends TestCase
{
    private const URL = 'http://h/mcp';

    private function assertNoSseAttempt(): void
    {
        Http::assertNotSent(fn (Request $r) => $r->method() === 'GET');
    }

    private function assertSseAttempt(): void
    {
        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && $r->url() === 'http://h/mcp/sse');
    }

    private function fakeUnauthorized(): void
    {
        Http::fake(function (Request $request) {
            if ($request->method() === 'GET') {
                return Http::response("event: endpoint\ndata: /messages?s=1\n\n");
            }

            return Http::response('bad token', 401);
        });
    }

    public function test_call_tool_401_on_streamable_http_does_not_try_sse(): void
    {
        $this->fakeUnauthorized();

        try {
            (new McpHttpClient)->callTool(self::URL, 'x');
            $this->fail('Expected an exception');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('HTTP 401', $e->getMessage());
        }

        $this->assertNoSseAttempt();
    }

    public function test_list_tools_401_on_streamable_http_does_not_try_sse(): void
    {
        $this->fakeUnauthorized();

        try {
            (new McpHttpClient)->listTools(self::URL);
            $this->fail('Expected an exception');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('HTTP 401', $e->getMessage());
        }

        $this->assertNoSseAttempt();
    }

    public function test_server_error_on_tools_call_after_initialize_is_reported_without_sse(): void
    {
        Http::fake(function (Request $request) {
            if ($request->method() === 'GET') {
                return Http::response("event: endpoint\ndata: /messages?s=1\n\n");
            }
            if (($request->data()['method'] ?? null) === 'initialize') {
                return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['protocolVersion' => '2024-11-05']]);
            }

            return Http::response('boom', 500);
        });

        try {
            (new McpHttpClient)->callTool(self::URL, 'x');
            $this->fail('Expected an exception');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('HTTP 500', $e->getMessage());
            $this->assertStringContainsString('boom', $e->getMessage());
        }

        $this->assertNoSseAttempt();
    }

    public function test_connection_timeout_does_not_try_sse(): void
    {
        Http::fake(function (Request $request) {
            if ($request->method() === 'GET') {
                return Http::response("event: endpoint\ndata: /messages?s=1\n\n");
            }

            throw new ConnectionException('Connection timed out');
        });

        try {
            (new McpHttpClient)->listTools(self::URL);
            $this->fail('Expected an exception');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Streamable HTTP', $e->getMessage());
            $this->assertStringContainsString('timed out', $e->getMessage());
        }

        $this->assertNoSseAttempt();
    }

    private function fakeSseOnlyServer(int $mcpStatus, string $mcpBody = ''): void
    {
        Http::fake(function (Request $request) use ($mcpStatus, $mcpBody) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($request->method() === 'GET') {
                return Http::response("event: endpoint\ndata: /messages?s=1\n\n");
            }
            if ($path === '/messages') {
                $method = $request->data()['method'] ?? null;

                return $method === 'tools/list'
                    ? Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['tools' => [['name' => 't1']]]])
                    : Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['content' => [['type' => 'text', 'text' => 'via sse']]]]);
            }

            return Http::response($mcpBody, $mcpStatus, ['Content-Type' => 'text/html']);
        });
    }

    public function test_404_on_streamable_http_falls_back_to_sse_and_succeeds(): void
    {
        $this->fakeSseOnlyServer(404);

        $client = new McpHttpClient;
        $this->assertSame('via sse', $client->callTool(self::URL, 'x'));
        $this->assertSame([['name' => 't1']], $client->listTools(self::URL));

        $this->assertSseAttempt();
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function fallbackStatuses(): array
    {
        return [
            'method not allowed' => [405],
            'unsupported media type' => [415],
        ];
    }

    #[DataProvider('fallbackStatuses')]
    public function test_405_and_415_fall_back_to_sse(int $status): void
    {
        $this->fakeSseOnlyServer($status);

        $this->assertSame('via sse', (new McpHttpClient)->callTool(self::URL, 'x'));

        $this->assertSseAttempt();
    }

    public function test_non_jsonrpc_200_on_initialize_falls_back_to_sse(): void
    {
        $this->fakeSseOnlyServer(200, '<html><body>Welcome</body></html>');

        $this->assertSame([['name' => 't1']], (new McpHttpClient)->listTools(self::URL));

        $this->assertSseAttempt();
    }

    public function test_both_transports_failing_names_both_and_the_first_cause(): void
    {
        Http::fake(fn () => Http::response('not here', 404));

        try {
            (new McpHttpClient)->callTool(self::URL, 'x');
            $this->fail('Expected an exception');
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
            $this->assertStringContainsString('Streamable HTTP', $message);
            $this->assertStringContainsString('HTTP 404', $message);
            $this->assertStringContainsString('SSE', $message);
            $this->assertStringContainsString('SSE connect failed: HTTP 404', $message);
            $this->assertLessThan(
                (int) strpos($message, 'SSE connect failed'),
                (int) strpos($message, 'Streamable HTTP'),
            );
        }

        $this->assertSseAttempt();
    }
}
