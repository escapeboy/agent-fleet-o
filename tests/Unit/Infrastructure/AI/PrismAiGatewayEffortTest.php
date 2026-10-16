<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Domain\Budget\Services\CostCalculator;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Enums\ReasoningEffort;
use App\Infrastructure\AI\Gateways\PrismAiGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Prism\Prism\Facades\Tool;
use Tests\TestCase;

/**
 * Models that reject budget_tokens (Opus 4.7+, every 5.x) must get the request's
 * effort as output_config.effort instead of having it dropped.
 */
class PrismAiGatewayEffortTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['prism.providers.anthropic.api_key' => 'test-key']);
    }

    private function fakeMessage(): void
    {
        Http::fake(['*' => Http::response([
            'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5',
            'content' => [['type' => 'text', 'text' => 'ok']],
            'stop_reason' => 'end_turn', 'stop_sequence' => null,
            'usage' => ['input_tokens' => 10, 'output_tokens' => 2],
        ])]);
    }

    private function fakeStream(): void
    {
        $events = [
            'message_start' => ['type' => 'message_start', 'message' => [
                'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5',
                'content' => [], 'stop_reason' => null, 'usage' => ['input_tokens' => 10, 'output_tokens' => 1],
            ]],
            'content_block_start' => ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
            'content_block_delta' => ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'ok']],
            'content_block_stop' => ['type' => 'content_block_stop', 'index' => 0],
            'message_delta' => ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn', 'stop_sequence' => null], 'usage' => ['output_tokens' => 2]],
            'message_stop' => ['type' => 'message_stop'],
        ];

        $sse = '';
        foreach ($events as $name => $data) {
            $sse .= "event: {$name}\ndata: ".json_encode($data)."\n\n";
        }

        Http::fake(['*' => Http::response($sse, 200, ['Content-Type' => 'text/event-stream'])]);
    }

    private function request(string $model, ?ReasoningEffort $effort = null, ?int $thinkingBudget = null, array $tools = []): AiRequestDTO
    {
        return new AiRequestDTO(
            provider: 'anthropic',
            model: $model,
            systemPrompt: 'sys',
            userPrompt: 'user',
            maxTokens: 1024,
            tools: $tools ?: null,
            effort: $effort,
            thinkingBudget: $thinkingBudget,
        );
    }

    /** @return array<string, mixed> */
    private function sentBody(): array
    {
        $body = null;
        Http::assertSent(function (Request $request) use (&$body): bool {
            $body = json_decode($request->body(), true);

            return true;
        });

        return $body;
    }

    /** @return array<string, array{ReasoningEffort, string}> */
    public static function effortLevels(): array
    {
        return [
            'low' => [ReasoningEffort::Low, 'low'],
            'medium' => [ReasoningEffort::Medium, 'medium'],
            'high' => [ReasoningEffort::High, 'high'],
            'none maps to low' => [ReasoningEffort::None, 'low'],
        ];
    }

    #[DataProvider('effortLevels')]
    public function test_complete_sends_effort_to_a_5x_model(ReasoningEffort $effort, string $expected): void
    {
        $this->fakeMessage();

        (new PrismAiGateway(new CostCalculator))->complete($this->request('claude-sonnet-5-5', $effort));

        $body = $this->sentBody();
        $this->assertSame($expected, $body['output_config']['effort'] ?? null);
        $this->assertArrayNotHasKey('thinking', $body);
    }

    public function test_resolved_auto_budget_maps_to_an_effort_level(): void
    {
        $this->fakeMessage();

        (new PrismAiGateway(new CostCalculator))->complete($this->request('claude-opus-5-5', ReasoningEffort::Auto, thinkingBudget: 8_000));

        $this->assertSame('medium', $this->sentBody()['output_config']['effort'] ?? null);
    }

    public function test_no_effort_sends_no_output_config(): void
    {
        $this->fakeMessage();

        (new PrismAiGateway(new CostCalculator))->complete($this->request('claude-haiku-5-5'));

        $this->assertArrayNotHasKey('output_config', $this->sentBody());
    }

    public function test_budget_models_still_get_a_thinking_budget_and_no_effort(): void
    {
        $this->fakeMessage();

        (new PrismAiGateway(new CostCalculator))->complete($this->request('claude-sonnet-4-6', ReasoningEffort::High));

        $body = $this->sentBody();
        $this->assertSame(['type' => 'enabled', 'budget_tokens' => 32_000], $body['thinking'] ?? null);
        $this->assertArrayNotHasKey('output_config', $body);
    }

    public function test_stream_sends_effort(): void
    {
        $this->fakeStream();

        $response = (new PrismAiGateway(new CostCalculator))->stream($this->request('claude-sonnet-5-5', ReasoningEffort::High));

        $this->assertSame('ok', $response->content);
        $this->assertSame('high', $this->sentBody()['output_config']['effort'] ?? null);
    }

    public function test_tool_request_keeps_an_empty_schema_object(): void
    {
        $this->fakeMessage();

        $tool = Tool::as('ping')->for('Ping')->using(fn (): string => 'pong');

        (new PrismAiGateway(new CostCalculator))->stream($this->request('claude-sonnet-5-5', ReasoningEffort::Low, tools: [$tool]));

        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body());

            return ($body->output_config->effort ?? null) === 'low'
                && $body->tools[0]->input_schema->properties instanceof \stdClass;
        });
    }
}
