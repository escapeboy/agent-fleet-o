<?php

namespace Tests\Unit\Domain\Decision;

use App\Domain\Decision\Drivers\LlmStructuredDriver;
use App\Domain\Decision\DTOs\ChoiceAnswer;
use App\Domain\Decision\Exceptions\DecisionRequestException;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LlmStructuredDriverTest extends TestCase
{
    /**
     * @return array<string, array<string, mixed>>
     */
    private function questions(): array
    {
        return [
            'domain' => [
                'type' => 'choice',
                'instructions' => 'Which tool domain comes next?',
                'criteria' => ['filesystem' => 'Reading or writing files', 'shell' => 'Running commands', 'other' => 'Anything else'],
            ],
            'urgent' => [
                'type' => 'noul',
                'instructions' => 'The task is time-sensitive',
            ],
        ];
    }

    private function gatewayReturning(?array $parsed, string $content = ''): AiGatewayInterface
    {
        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')
            ->once()
            ->andReturn(new AiResponseDTO(
                content: $content,
                parsedOutput: $parsed,
                usage: new AiUsageDTO(promptTokens: 1234, completionTokens: 56, costCredits: 7),
                provider: 'anthropic',
                model: 'claude-haiku-4-5-20251001',
                latencyMs: 11,
            ));

        return $gateway;
    }

    #[Test]
    public function it_maps_structured_output_onto_the_shared_answer_dtos(): void
    {
        $driver = new LlmStructuredDriver(
            $this->gatewayReturning([
                'answers' => [
                    ['id' => 'domain', 'value' => 'shell', 'probabilities' => [
                        ['option' => 'filesystem', 'probability' => 0.25],
                        ['option' => 'shell', 'probability' => 0.70],
                        ['option' => 'other', 'probability' => 0.05],
                    ]],
                    ['id' => 'urgent', 'value' => '0.8'],
                ],
            ]),
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
        );

        $result = $driver->decide(['task' => 'run the suite'], $this->questions());

        $this->assertSame('claude-haiku-4-5-20251001', $result->model);
        $this->assertSame(1234, $result->inputTokens);

        $domain = $result->answer('domain');
        $this->assertInstanceOf(ChoiceAnswer::class, $domain);
        $this->assertSame('shell', $domain->choice);
        $this->assertEqualsWithDelta(0.70, $domain->probabilities['shell'], 1e-9);
        $this->assertEqualsWithDelta(0.70, $domain->confidence, 1e-9);

        $this->assertSame(0.8, $result->answer('urgent')?->value());
    }

    #[Test]
    public function probabilities_are_renormalised_when_the_model_does_not_sum_to_one(): void
    {
        $driver = new LlmStructuredDriver(
            $this->gatewayReturning([
                'answers' => [
                    ['id' => 'domain', 'value' => 'shell', 'probabilities' => [
                        ['option' => 'filesystem', 'probability' => 0.5],
                        ['option' => 'shell', 'probability' => 1.5],
                    ]],
                ],
            ]),
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
        );

        $probabilities = $driver->decide('state', $this->questions())->answer('domain')?->probabilities();

        $this->assertEqualsWithDelta(1.0, array_sum($probabilities), 1e-9);
        $this->assertEqualsWithDelta(0.75, $probabilities['shell'], 1e-9);
    }

    #[Test]
    public function a_provider_that_returns_no_distribution_leaves_probabilities_null(): void
    {
        $driver = new LlmStructuredDriver(
            $this->gatewayReturning(['answers' => [['id' => 'domain', 'value' => 'shell']]]),
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
        );

        $answer = $driver->decide('state', $this->questions())->answer('domain');

        $this->assertNull($answer?->probabilities());
        $this->assertNull($answer?->confidence());
    }

    #[Test]
    public function it_falls_back_to_parsing_raw_content_when_the_gateway_does_not_pre_parse(): void
    {
        $driver = new LlmStructuredDriver(
            $this->gatewayReturning(null, json_encode(['answers' => [['id' => 'domain', 'value' => 'filesystem']]])),
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
        );

        $this->assertSame('filesystem', $driver->decide('state', $this->questions())->answer('domain')?->value());
    }

    #[Test]
    public function unparsable_output_is_an_error_rather_than_a_silent_wrong_answer(): void
    {
        $driver = new LlmStructuredDriver(
            $this->gatewayReturning(null, 'I think you should use the shell.'),
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
        );

        $this->expectException(DecisionRequestException::class);

        $driver->decide('state', $this->questions());
    }

    #[Test]
    public function the_request_carries_a_schema_and_a_zero_temperature(): void
    {
        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')
            ->once()
            ->with(Mockery::on(function (AiRequestDTO $request): bool {
                return $request->isStructured()
                    && $request->temperature === 0.0
                    && $request->purpose === 'decision_eval'
                    && str_contains($request->systemPrompt, 'filesystem');
            }))
            ->andReturn(new AiResponseDTO(
                content: '',
                parsedOutput: ['answers' => [['id' => 'domain', 'value' => 'shell']]],
                usage: new AiUsageDTO(1, 1, 1),
                provider: 'anthropic',
                model: 'claude-haiku-4-5-20251001',
                latencyMs: 1,
            ));

        (new LlmStructuredDriver($gateway, 'anthropic', 'claude-haiku-4-5-20251001'))
            ->decide('state', $this->questions());
    }
}
