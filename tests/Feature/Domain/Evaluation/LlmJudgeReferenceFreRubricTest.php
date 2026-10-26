<?php

namespace Tests\Feature\Domain\Evaluation;

use App\Domain\Evaluation\Services\LlmJudge;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use Mockery;
use Tests\TestCase;

/**
 * Verifies that LlmJudge uses a reference-free rubric when no expected output
 * is provided, and the existing reference-based rubric when one is present.
 */
class LlmJudgeReferenceFreRubricTest extends TestCase
{
    public function test_judge_uses_reference_free_rubric_when_no_expected_output(): void
    {
        $capturedPrompt = null;

        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')
            ->once()
            ->withArgs(function (AiRequestDTO $dto) use (&$capturedPrompt) {
                $capturedPrompt = $dto->userPrompt;

                return true;
            })
            ->andReturn(new AiResponseDTO(
                content: json_encode(['score' => 7.5, 'reasoning' => 'Delivered and mostly complete.']),
                parsedOutput: null,
                usage: new AiUsageDTO(promptTokens: 100, completionTokens: 50, costCredits: 0),
                provider: 'anthropic',
                model: 'claude-sonnet-4-5',
                latencyMs: 10,
            ));

        $judge = new LlmJudge($gateway);
        $result = $judge->evaluate(
            criterion: 'correctness',
            input: 'What is the capital of France?',
            actualOutput: 'Paris is the capital of France.',
            expectedOutput: null,
        );

        $this->assertSame(7.5, $result['score']);

        // Reference-free rubric must be present
        $this->assertStringContainsString('Delivered', $capturedPrompt);
        $this->assertStringContainsString('Internally consistent', $capturedPrompt);
        $this->assertStringContainsString('no reference answer available', $capturedPrompt);

        // Must NOT contain the <expected_output> tag
        $this->assertStringNotContainsString('<expected_output>', $capturedPrompt);
    }

    public function test_judge_uses_config_rubric_when_expected_output_is_provided(): void
    {
        $capturedPrompt = null;

        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')
            ->once()
            ->withArgs(function (AiRequestDTO $dto) use (&$capturedPrompt) {
                $capturedPrompt = $dto->userPrompt;

                return true;
            })
            ->andReturn(new AiResponseDTO(
                content: json_encode(['score' => 9.0, 'reasoning' => 'Matches expected.']),
                parsedOutput: null,
                usage: new AiUsageDTO(promptTokens: 100, completionTokens: 50, costCredits: 0),
                provider: 'anthropic',
                model: 'claude-sonnet-4-5',
                latencyMs: 10,
            ));

        $judge = new LlmJudge($gateway);
        $result = $judge->evaluate(
            criterion: 'correctness',
            input: 'What is the capital of France?',
            actualOutput: 'Paris is the capital of France.',
            expectedOutput: 'Paris.',
        );

        $this->assertSame(9.0, $result['score']);

        // Config rubric content from evaluation.php
        $this->assertStringContainsString('<expected_output>Paris.</expected_output>', $capturedPrompt);

        // Must NOT use the reference-free rubric description
        $this->assertStringNotContainsString('no reference answer available', $capturedPrompt);
    }
}
