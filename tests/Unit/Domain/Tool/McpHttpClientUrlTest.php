<?php

namespace Tests\Unit\Domain\Tool;

use App\Domain\Tool\Services\McpHttpClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class McpHttpClientUrlTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function urls(): array
    {
        return [
            'bare host' => ['http://h', 'http://h/mcp'],
            'bare host slash' => ['http://h/', 'http://h/mcp'],
            'already mcp' => ['http://h/mcp', 'http://h/mcp'],
            'already mcp slash' => ['http://h/mcp/', 'http://h/mcp'],
            'path' => ['http://h/e/1', 'http://h/e/1/mcp'],
            'path slash' => ['http://h/e/1/', 'http://h/e/1/mcp'],
            'query' => ['http://h/e/1?k=v', 'http://h/e/1/mcp?k=v'],
            'mcp with query' => ['http://h/v1/mcp?k=v', 'http://h/v1/mcp?k=v'],
            'fragment' => ['http://h/e/1#f', 'http://h/e/1/mcp#f'],
        ];
    }

    #[DataProvider('urls')]
    public function test_list_tools_posts_to_the_expected_url(string $input, string $expected): void
    {
        Http::fake(['*' => Http::response(['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => []]])]);

        (new McpHttpClient)->listTools($input);

        $posted = Http::recorded()->map(fn (array $pair) => $pair[0]->url())->all();
        $this->assertNotEmpty($posted);
        foreach ($posted as $url) {
            $this->assertSame($expected, $url);
        }
    }

    #[DataProvider('urls')]
    public function test_call_tool_posts_to_the_expected_url(string $input, string $expected): void
    {
        Http::fake(['*' => Http::response(['jsonrpc' => '2.0', 'id' => 2, 'result' => ['content' => [['type' => 'text', 'text' => 'ok']]]])]);

        $this->assertSame('ok', (new McpHttpClient)->callTool($input, 'x'));

        Http::assertSent(fn (Request $r) => $r->url() === $expected);
        Http::assertNotSent(fn (Request $r) => $r->url() !== $expected);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function sseUrls(): array
    {
        return [
            'bare host' => ['http://h', 'http://h/sse', 'http://h/messages?sessionId=1'],
            'path' => ['http://h/e/1', 'http://h/e/1/sse', 'http://h/messages?sessionId=1'],
            'query' => ['http://h/e/1?k=v', 'http://h/e/1/sse?k=v', 'http://h/messages?sessionId=1&k=v'],
            'fragment' => ['http://h/e/1#f', 'http://h/e/1/sse#f', 'http://h/messages?sessionId=1'],
            'ends in mcp (unchanged: /mcp/sse)' => ['http://h/v1/mcp', 'http://h/v1/mcp/sse', 'http://h/messages?sessionId=1'],
        ];
    }

    #[DataProvider('sseUrls')]
    public function test_sse_fallback_urls(string $input, string $sseUrl, string $sessionUrl): void
    {
        Http::fake([
            '*/sse*' => Http::response("event: endpoint\ndata: /messages?sessionId=1\n\n"),
            '*/mcp*' => Http::response('', 500),
            'http://h/messages*' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['tools' => []]]),
        ]);

        (new McpHttpClient)->listTools($input);

        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && $r->url() === $sseUrl);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === $sessionUrl);
    }
}
