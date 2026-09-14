<?php

namespace Tests\Unit\Domain\Assistant;

use App\Domain\Assistant\Actions\LocalToolLoopExecutor;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Models\User;
use Mockery;
use Prism\Prism\Facades\Tool as PrismTool;
use Prism\Prism\Tool as PrismToolObject;
use Prism\Prism\ValueObjects\ToolOutput;
use RuntimeException;
use Tests\TestCase;

/**
 * The text-based tool loop for local agents feeds tool results back into the
 * next prompt. A failing tool must not put an uncapped exception message there.
 */
class LocalToolLoopExecutorErrorTest extends TestCase
{
    private function response(string $content): AiResponseDTO
    {
        return new AiResponseDTO(
            content: $content,
            parsedOutput: null,
            usage: new AiUsageDTO(promptTokens: 1, completionTokens: 1, costCredits: 0),
            provider: 'claude-code',
            model: 'local',
            latencyMs: 1,
        );
    }

    /**
     * Runs one tool call through the loop and returns the prompt of the second
     * LLM request, which carries the tool result.
     */
    private function secondPromptFor(PrismToolObject $tool): string
    {
        $prompts = [];
        $responses = [
            $this->response('<tool_call>{"name":"boom","arguments":{"query":"SECRET-ARG-VALUE"}}</tool_call>'),
            $this->response('done'),
        ];

        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')->andReturnUsing(function (AiRequestDTO $request) use (&$prompts, &$responses) {
            $prompts[] = $request->userPrompt;

            return array_shift($responses) ?? $this->response('done');
        });

        $user = new User;
        $user->id = 'user-1';
        $user->current_team_id = 'team-1';

        (new LocalToolLoopExecutor($gateway))->execute('claude-code', 'local', 'system', 'hello', [$tool], $user);

        $this->assertCount(2, $prompts);

        return $prompts[1];
    }

    public function test_tool_error_result_is_capped_and_does_not_echo_arguments(): void
    {
        $tool = PrismTool::as('boom')
            ->for('throws')
            ->withStringParameter('query', 'q')
            ->using(function (string $query): string {
                throw new RuntimeException(str_repeat('x', 50_000));
            });

        $prompt = $this->secondPromptFor($tool);

        $this->assertStringContainsString('Tool boom failed (RuntimeException)', $prompt);
        $this->assertStringNotContainsString(str_repeat('x', 2_000), $prompt);
        $this->assertStringNotContainsString('SECRET-ARG-VALUE', $prompt);
    }

    public function test_structured_tool_output_reaches_the_next_prompt(): void
    {
        // ToolOutput exposes `result`; reading a non-existent `output` used to drop it.
        $tool = PrismTool::as('boom')
            ->for('returns a structured result')
            ->withStringParameter('query', 'q')
            ->using(fn (string $query): ToolOutput => new ToolOutput('STRUCTURED-RESULT-'.$query));

        $prompt = $this->secondPromptFor($tool);

        $this->assertStringContainsString('STRUCTURED-RESULT-SECRET-ARG-VALUE', $prompt);
    }

    public function test_rethrown_exception_is_capped_in_the_catch_path(): void
    {
        $tool = PrismTool::as('boom')
            ->for('throws')
            ->withStringParameter('query', 'q')
            ->withoutErrorHandling()
            ->using(function (string $query): string {
                throw new RuntimeException('bad '.str_repeat('z', 50_000));
            });

        $prompt = $this->secondPromptFor($tool);

        $this->assertStringContainsString('failed (', $prompt);
        $this->assertStringNotContainsString(str_repeat('z', 2_000), $prompt);
    }
}
