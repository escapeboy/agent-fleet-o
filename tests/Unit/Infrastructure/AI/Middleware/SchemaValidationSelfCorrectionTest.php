<?php

namespace Tests\Unit\Infrastructure\AI\Middleware;

use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Middleware\SchemaValidation;
use Prism\Prism\Schema\ObjectSchema;
use Tests\TestCase;

class SchemaValidationSelfCorrectionTest extends TestCase
{
    private function structuredRequest(): AiRequestDTO
    {
        return new AiRequestDTO(
            provider: 'custom_endpoint',
            model: 'local-model',
            systemPrompt: 'sys',
            userPrompt: 'extract the invoice',
            outputSchema: new ObjectSchema('out', 'desc', []),
        );
    }

    private function response(?array $parsed, string $content = ''): AiResponseDTO
    {
        return new AiResponseDTO(
            content: $content,
            parsedOutput: $parsed,
            usage: new AiUsageDTO(promptTokens: 1, completionTokens: 1, costCredits: 0),
            provider: 'custom_endpoint',
            model: 'local-model',
            latencyMs: 1,
        );
    }

    public function test_no_retry_when_disabled(): void
    {
        config()->set('ai_routing.structured_self_correction.enabled', false);

        $calls = 0;
        $middleware = new SchemaValidation;

        $result = $middleware->handle($this->structuredRequest(), function () use (&$calls) {
            $calls++;

            return $this->response(null, 'not json');
        });

        $this->assertSame(1, $calls, 'disabled flag must not trigger a retry');
        $this->assertFalse($result->schemaValid);
        $this->assertNull($result->parsedOutput);
    }

    public function test_retry_recovers_valid_output_when_enabled(): void
    {
        config()->set('ai_routing.structured_self_correction.enabled', true);
        config()->set('ai_routing.structured_self_correction.max_attempts', 1);

        $calls = 0;
        $middleware = new SchemaValidation;

        $result = $middleware->handle($this->structuredRequest(), function (AiRequestDTO $req) use (&$calls) {
            $calls++;

            // First call: model returns prose. Retry: a corrective prompt is
            // appended, and the model returns a valid object.
            if ($calls === 1) {
                $this->assertSame('extract the invoice', $req->userPrompt);

                return $this->response(null, 'Here is the invoice...');
            }

            $this->assertStringContainsString('valid JSON object', $req->userPrompt);

            return $this->response(['invoice' => 'INV-1']);
        });

        $this->assertSame(2, $calls, 'one retry expected when first parse fails');
        $this->assertTrue($result->schemaValid);
        $this->assertSame(['invoice' => 'INV-1'], $result->parsedOutput);
    }

    public function test_gives_up_after_max_attempts(): void
    {
        config()->set('ai_routing.structured_self_correction.enabled', true);
        config()->set('ai_routing.structured_self_correction.max_attempts', 2);

        $calls = 0;
        $middleware = new SchemaValidation;

        $result = $middleware->handle($this->structuredRequest(), function () use (&$calls) {
            $calls++;

            return $this->response(null, 'still not json');
        });

        // 1 initial + 2 retries = 3 provider calls, then give up.
        $this->assertSame(3, $calls);
        $this->assertFalse($result->schemaValid);
        $this->assertNull($result->parsedOutput);
    }

    public function test_non_structured_request_is_untouched(): void
    {
        config()->set('ai_routing.structured_self_correction.enabled', true);

        $calls = 0;
        $middleware = new SchemaValidation;

        $request = new AiRequestDTO(
            provider: 'custom_endpoint',
            model: 'local-model',
            systemPrompt: 'sys',
            userPrompt: 'just chat',
        );

        $result = $middleware->handle($request, function () use (&$calls) {
            $calls++;

            return $this->response(null, 'plain text reply');
        });

        $this->assertSame(1, $calls, 'non-structured requests must never retry');
        $this->assertTrue($result->schemaValid);
    }
}
