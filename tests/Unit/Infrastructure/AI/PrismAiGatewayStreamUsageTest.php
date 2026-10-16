<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Domain\Budget\Services\CostCalculator;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\Gateways\PrismAiGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Streamed calls must bill the cache buckets from the stream's final usage, the
 * same way non-streamed calls do. Expected values are computed by hand from the
 * claude-haiku-5-5 row in config/llm_pricing.php.
 */
class PrismAiGatewayStreamUsageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['prism.providers.anthropic.api_key' => 'test-key']);

        // 2 000 uncached + 150 000 cache-read input tokens, 500 output tokens.
        Http::fake(['*' => Http::response($this->anthropicSse(), 200, ['Content-Type' => 'text/event-stream'])]);
    }

    private function anthropicSse(): string
    {
        $events = [
            'message_start' => ['type' => 'message_start', 'message' => [
                'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-haiku-5-5',
                'content' => [], 'stop_reason' => null,
                'usage' => ['input_tokens' => 2000, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 150000, 'output_tokens' => 1],
            ]],
            'content_block_start' => ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
            'content_block_delta' => ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Hello']],
            'content_block_stop' => ['type' => 'content_block_stop', 'index' => 0],
            'message_delta' => ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn', 'stop_sequence' => null], 'usage' => ['output_tokens' => 500]],
            'message_stop' => ['type' => 'message_stop'],
        ];

        $sse = '';
        foreach ($events as $name => $data) {
            $sse .= "event: {$name}\ndata: ".json_encode($data)."\n\n";
        }

        return $sse;
    }

    private function stream(): AiResponseDTO
    {
        $request = new AiRequestDTO(
            provider: 'anthropic',
            model: 'claude-haiku-5-5',
            systemPrompt: 'sys',
            userPrompt: 'user',
            maxTokens: 1024,
            enablePromptCaching: true,
        );

        return (new PrismAiGateway(new CostCalculator))->stream($request);
    }

    public function test_accurate_mode_bills_stream_cache_reads_at_the_long_context_tier(): void
    {
        config(['llm_pricing.accurate_usage_cost' => true]);

        $response = $this->stream();

        // Prompt = 152 000 tokens > 100 000 → long-context tier:
        // 2 000·0.50 + 150 000·0.05 + 500·2.50 per Mtok = 0.00975 USD → 10 credits.
        // Before the fix the stream reported 0 cache reads: 2 000·0.10 + 500·0.50 → 1 credit.
        $this->assertSame('Hello', $response->content);
        $this->assertTrue($response->streamed);
        $this->assertSame(150000, $response->usage->cachedInputTokens);
        $this->assertSame(0, $response->usage->cacheWriteInputTokens);
        $this->assertSame(500, $response->usage->completionTokens);
        $this->assertSame(10, $response->usage->costCredits);
    }

    public function test_legacy_mode_records_the_accurate_cost_and_cache_reads(): void
    {
        config(['llm_pricing.accurate_usage_cost' => false]);

        $response = $this->stream();

        $this->assertSame(150000, $response->usage->cachedInputTokens);
        $this->assertSame(10, $response->usage->accurateCostCredits);
    }

    public function test_stream_request_marks_the_system_prompt_cacheable(): void
    {
        $this->stream();

        Http::assertSent(function (Request $request): bool {
            $system = $request->data()['system'] ?? null;

            return is_array($system) && ($system[0]['cache_control']['type'] ?? null) === 'ephemeral';
        });
    }
}
