<?php

namespace Tests\Unit\Domain\Signal\Services;

use App\Domain\Signal\Models\Signal;
use App\Domain\Signal\Services\AutoBugReportClassifier;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use Mockery;
use Tests\TestCase;

class AutoBugReportClassifierTest extends TestCase
{
    private function makeSignal(array $payload = []): Signal
    {
        $signal = new Signal;
        $signal->id = 'test-signal-id';
        $signal->team_id = 'test-team-id';
        $signal->payload = $payload ?: ['title' => 'Бутонът не работи', 'description' => 'Кликвам Запази и нищо не се случва.', 'severity' => 'major'];

        return $signal;
    }

    private function makeResponse(string $content): AiResponseDTO
    {
        return new AiResponseDTO(
            content: $content,
            parsedOutput: null,
            usage: new AiUsageDTO(promptTokens: 10, completionTokens: 20, costCredits: 0),
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            latencyMs: 0,
        );
    }

    public function test_happy_path_returns_classified_type_confidence_and_rationale(): void
    {
        $json = json_encode([
            'classified_type' => 'bug',
            'confidence' => 0.92,
            'rationale_bg' => 'Функция не работи при натискане на бутон.',
        ]);

        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->expects('complete')->once()->andReturn($this->makeResponse($json));

        $classifier = new AutoBugReportClassifier($gateway);
        $result = $classifier->classify($this->makeSignal());

        $this->assertSame('bug', $result['classified_type']);
        $this->assertEqualsWithDelta(0.92, $result['confidence'], 0.001);
        $this->assertNotEmpty($result['rationale']);
    }

    public function test_malformed_json_throws_runtime_exception(): void
    {
        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->expects('complete')->once()->andReturn($this->makeResponse('this is not json'));

        $classifier = new AutoBugReportClassifier($gateway);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('malformed JSON');

        $classifier->classify($this->makeSignal());
    }

    public function test_unknown_classified_type_throws_runtime_exception(): void
    {
        $json = json_encode([
            'classified_type' => 'question',
            'confidence' => 0.5,
            'rationale_bg' => 'Не знам.',
        ]);

        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->expects('complete')->once()->andReturn($this->makeResponse($json));

        $classifier = new AutoBugReportClassifier($gateway);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown classified_type');

        $classifier->classify($this->makeSignal());
    }
}
