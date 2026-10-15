<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Domain\Budget\Services\CostCalculator;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Gateways\PrismAiGateway;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PrismAiGatewayTemperatureTest extends TestCase
{
    private function temperatureFor(string $provider, string $model): ?float
    {
        $gateway = new PrismAiGateway(new CostCalculator);
        $method = (new \ReflectionClass($gateway))->getMethod('temperatureFor');
        $method->setAccessible(true);

        return $method->invoke($gateway, new AiRequestDTO(
            provider: $provider,
            model: $model,
            systemPrompt: 'sys',
            userPrompt: 'user',
            temperature: 0.4,
        ));
    }

    /** @return array<string, array{string}> */
    public static function modelsWithoutSampling(): array
    {
        return [
            'opus 4.7' => ['claude-opus-4-7'],
            'opus 5.5' => ['claude-opus-5-5'],
            'sonnet 5' => ['claude-sonnet-5'],
            'sonnet 5.5' => ['claude-sonnet-5-5'],
            'haiku 5.5' => ['claude-haiku-5-5'],
            'fable 5.1' => ['claude-fable-5-1'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function modelsWithSampling(): array
    {
        return [
            'haiku 4.5' => ['claude-haiku-4-5'],
            'haiku 4.5 dated' => ['claude-haiku-4-5-20251001'],
            'sonnet 4.6' => ['claude-sonnet-4-6'],
            'opus 4.6' => ['claude-opus-4-6'],
        ];
    }

    #[DataProvider('modelsWithoutSampling')]
    public function test_drops_temperature_for_models_that_reject_it(string $model): void
    {
        $this->assertNull($this->temperatureFor('anthropic', $model));
    }

    #[DataProvider('modelsWithSampling')]
    public function test_keeps_temperature_for_older_anthropic_models(string $model): void
    {
        $this->assertSame(0.4, $this->temperatureFor('anthropic', $model));
    }

    public function test_keeps_temperature_for_other_providers(): void
    {
        $this->assertSame(0.4, $this->temperatureFor('openai', 'haiku-5-lookalike'));
    }
}
