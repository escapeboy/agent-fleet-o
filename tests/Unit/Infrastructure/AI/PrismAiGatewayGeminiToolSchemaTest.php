<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Domain\Budget\Services\CostCalculator;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Gateways\PrismAiGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Schema\RawSchema;
use Prism\Prism\Tool;
use Tests\TestCase;

/**
 * Wiring for GeminiToolSchema: the wire body sent to Gemini must carry no
 * list-valued `type` (Sentry fleetq #1110), while other providers keep the
 * JSON Schema union they accept.
 */
class PrismAiGatewayGeminiToolSchemaTest extends TestCase
{
    private function request(string $provider, string $model): AiRequestDTO
    {
        $tool = (new Tool)
            ->as('experiment_update')
            ->for('Update an experiment')
            ->withParameter(new RawSchema('title', ['type' => ['string', 'null']]), required: false)
            ->using(fn (): string => 'ok');

        return new AiRequestDTO(
            provider: $provider,
            model: $model,
            systemPrompt: 'sys',
            userPrompt: 'hi',
            tools: [$tool],
            maxSteps: 1,
        );
    }

    private function send(AiRequestDTO $request): void
    {
        $method = (new \ReflectionClass(PrismAiGateway::class))->getMethod('executeRequest');

        try {
            $method->invoke(new PrismAiGateway(new CostCalculator), $request);
        } catch (\Throwable) {
            // Only the outgoing request body matters here.
        }
    }

    public function test_gemini_request_has_no_list_valued_type(): void
    {
        config(['prism.providers.gemini.api_key' => 'test-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => 'ok']], 'role' => 'model'], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
        ])]);

        $this->send($this->request('google', 'gemini-2.5-flash'));

        Http::assertSent(function (Request $sent): bool {
            $title = data_get($sent->data(), 'tools.function_declarations.0.parameters.properties.title');

            return $title === ['type' => 'string', 'nullable' => true];
        });
    }

    public function test_anthropic_request_keeps_the_json_schema_union(): void
    {
        config(['prism.providers.anthropic.api_key' => 'test-key']);
        Http::fake(['api.anthropic.com/*' => Http::response([
            'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-4-5',
            'content' => [['type' => 'text', 'text' => 'ok']],
            'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])]);

        $this->send($this->request('anthropic', 'claude-sonnet-4-5'));

        Http::assertSent(fn (Request $sent): bool => data_get($sent->data(), 'tools.0.input_schema.properties.title.type') === ['string', 'null']);
    }
}
