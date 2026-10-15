<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Domain\Budget\Services\CostCalculator;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\Gateways\PrismAiGateway;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Legacy billing (llm_pricing.accurate_usage_cost = false) on non-streamed calls,
 * parsed by Prism's real text handlers. Expected values are computed by hand from
 * config/llm_pricing.php.
 */
class PrismAiGatewayLegacyUsageCostTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'llm_pricing.accurate_usage_cost' => false,
            'prism.providers.openai.api_key' => 'test-key',
            'prism.providers.anthropic.api_key' => 'test-key',
            'prism.providers.gemini.api_key' => 'test-key',
        ]);
    }

    private function complete(string $provider, string $model, bool $enablePromptCaching = false): AiResponseDTO
    {
        $request = new AiRequestDTO(
            provider: $provider,
            model: $model,
            systemPrompt: 'sys',
            userPrompt: 'user',
            maxTokens: 2048,
            enablePromptCaching: $enablePromptCaching,
        );

        return (new PrismAiGateway(new CostCalculator))->complete($request);
    }

    public function test_openai_cached_tokens_are_billed_like_the_stream(): void
    {
        // 100 000 input tokens of which 90 000 cached, 1 000 output tokens.
        Http::fake(['*' => Http::response([
            'id' => 'resp_1', 'object' => 'response', 'model' => 'gpt-5', 'status' => 'completed',
            'output' => [[
                'type' => 'message', 'id' => 'msg_1', 'status' => 'completed', 'role' => 'assistant',
                'content' => [['type' => 'output_text', 'text' => 'Hello', 'annotations' => []]],
            ]],
            'usage' => [
                'input_tokens' => 100000,
                'input_tokens_details' => ['cached_tokens' => 90000],
                'output_tokens' => 1000,
                'output_tokens_details' => ['reasoning_tokens' => 0],
                'total_tokens' => 101000,
            ],
        ])]);

        $response = $this->complete('openai', 'gpt-5');

        // 10 000·1.25 + 90 000·0.13 + 1 000·10.00 per Mtok = 0.0342 USD → 35 credits,
        // the same as PrismAiGatewayOpenAiStreamUsageTest. Prism's text handler already
        // drops the cached tokens from promptTokens, so capping the cache reads at it
        // billed 10 000·0.13 + 1 000·10.00 → 12 credits.
        $this->assertSame('Hello', $response->content);
        $this->assertSame(10000, $response->usage->promptTokens);
        $this->assertSame(90000, $response->usage->cachedInputTokens);
        $this->assertSame(35, $response->usage->costCredits);
        $this->assertSame(35, $response->usage->accurateCostCredits);
    }

    public function test_anthropic_legacy_cost_is_unchanged(): void
    {
        // 10 000 uncached + 90 000 cache-read input tokens, 1 000 output tokens.
        Http::fake(['*' => Http::response([
            'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-4-5',
            'content' => [['type' => 'text', 'text' => 'Hello']],
            'stop_reason' => 'end_turn', 'stop_sequence' => null,
            'usage' => [
                'input_tokens' => 10000,
                'cache_creation_input_tokens' => 0,
                'cache_read_input_tokens' => 90000,
                'output_tokens' => 1000,
            ],
        ])]);

        $response = $this->complete('anthropic', 'claude-sonnet-4-5', enablePromptCaching: true);

        // Current legacy value, pinned so this PR does not move it:
        // cache reads capped at promptTokens → 10 000·0.30 + 1 000·15.00, plus the 5m
        // write surcharge on 10 000·3.75 = 0.0555 USD → 56 credits.
        // The accurate figure is 10 000·3.00 + 90 000·0.30 + 1 000·15.00 → 72 credits.
        $this->assertSame(56, $response->usage->costCredits);
        $this->assertSame(72, $response->usage->accurateCostCredits);
    }

    public function test_gemini_legacy_cost_is_unchanged(): void
    {
        // 100 000 prompt tokens of which 90 000 cached, 1 000 output tokens.
        Http::fake(['*' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [['text' => 'Hello']], 'role' => 'model'],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => [
                'promptTokenCount' => 100000,
                'cachedContentTokenCount' => 90000,
                'candidatesTokenCount' => 1000,
                'totalTokenCount' => 101000,
            ],
            'modelVersion' => 'gemini-2.5-flash',
        ])]);

        $response = $this->complete('google', 'gemini-2.5-flash');

        // gemini-2.5-flash has no cache-read rate, so reads fall back to the input rate:
        // 100 000·0.30 + 1 000·2.50 per Mtok = 0.0325 USD → 33 credits in both modes.
        $this->assertSame(100000, $response->usage->promptTokens);
        $this->assertSame(33, $response->usage->costCredits);
        $this->assertSame(33, $response->usage->accurateCostCredits);
    }
}
