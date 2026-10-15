<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Domain\Budget\Services\CostCalculator;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\Gateways\PrismAiGateway;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Prism's OpenAI stream handler reports promptTokens = input_tokens INCLUDING the
 * cached tokens, while its text handler subtracts them. A streamed OpenAI call must
 * not bill the cached tokens at the input rate and again at the cache-read rate.
 * Expected values are computed by hand from the gpt-5 row in config/llm_pricing.php.
 */
class PrismAiGatewayOpenAiStreamUsageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['prism.providers.openai.api_key' => 'test-key']);

        // 100 000 input tokens of which 90 000 cached, 1 000 output tokens.
        Http::fake(['*' => Http::response($this->openAiSse(), 200, ['Content-Type' => 'text/event-stream'])]);
    }

    private function openAiSse(): string
    {
        $events = [
            ['type' => 'response.created', 'response' => ['id' => 'resp_1', 'model' => 'gpt-5', 'status' => 'in_progress']],
            ['type' => 'response.output_text.delta', 'item_id' => 'msg_1', 'output_index' => 0, 'content_index' => 0, 'delta' => 'Hello'],
            ['type' => 'response.output_text.done', 'item_id' => 'msg_1', 'output_index' => 0, 'content_index' => 0, 'text' => 'Hello'],
            ['type' => 'response.completed', 'response' => [
                'id' => 'resp_1', 'model' => 'gpt-5', 'status' => 'completed',
                'usage' => [
                    'input_tokens' => 100000,
                    'input_tokens_details' => ['cached_tokens' => 90000],
                    'output_tokens' => 1000,
                    'output_tokens_details' => ['reasoning_tokens' => 0],
                    'total_tokens' => 101000,
                ],
            ]],
        ];

        $sse = '';
        foreach ($events as $data) {
            $sse .= "event: {$data['type']}\ndata: ".json_encode($data)."\n\n";
        }

        return $sse."data: [DONE]\n\n";
    }

    private function stream(): AiResponseDTO
    {
        $request = new AiRequestDTO(
            provider: 'openai',
            model: 'gpt-5',
            systemPrompt: 'sys',
            userPrompt: 'user',
            maxTokens: 2048,
        );

        return (new PrismAiGateway(new CostCalculator))->stream($request);
    }

    public function test_accurate_mode_bills_stream_cache_reads_once(): void
    {
        config(['llm_pricing.accurate_usage_cost' => true]);

        $response = $this->stream();

        // 10 000·1.25 + 90 000·0.13 + 1 000·10.00 per Mtok = 0.0342 USD → 35 credits.
        // Billing the cached tokens as input too gave 100 000·1.25 + … → 147 credits.
        $this->assertSame('Hello', $response->content);
        $this->assertSame(10000, $response->usage->promptTokens);
        $this->assertSame(90000, $response->usage->cachedInputTokens);
        $this->assertSame(1000, $response->usage->completionTokens);
        $this->assertSame(35, $response->usage->costCredits);
    }

    public function test_legacy_mode_bill_and_shadow_accurate_cost_agree(): void
    {
        config(['llm_pricing.accurate_usage_cost' => false]);

        $response = $this->stream();

        // Legacy treats promptTokens as the total and splits off the cache reads.
        $this->assertSame(35, $response->usage->costCredits);
        $this->assertSame(35, $response->usage->accurateCostCredits);
    }
}
