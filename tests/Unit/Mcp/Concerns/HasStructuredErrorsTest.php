<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp\Concerns;

use App\Mcp\Concerns\HasStructuredErrors;
use App\Mcp\ErrorCode;
use Laravel\Mcp\Response;
use Tests\TestCase;

class HasStructuredErrorsTest extends TestCase
{
    private object $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tool = new class
        {
            use HasStructuredErrors {
                errorResponse as public;
                notFoundError as public;
                permissionDeniedError as public;
                invalidArgumentError as public;
                failedPreconditionError as public;
                resourceExhaustedError as public;
                unavailableError as public;
                deadlineExceededError as public;
            }
        };
    }

    public function test_not_found_error_formats_message(): void
    {
        $response = $this->tool->notFoundError('agent', 'abc-123');

        $payload = $this->decode($response);
        $this->assertSame('NOT_FOUND', $payload['error']['code']);
        $this->assertFalse($payload['error']['retryable']);
        $this->assertStringContainsString('abc-123', $payload['error']['message']);
    }

    public function test_not_found_error_without_id(): void
    {
        $response = $this->tool->notFoundError('agent');

        $payload = $this->decode($response);
        $this->assertSame('Agent not found.', $payload['error']['message']);
    }

    public function test_permission_denied_default_message(): void
    {
        $response = $this->tool->permissionDeniedError();

        $payload = $this->decode($response);
        $this->assertSame('PERMISSION_DENIED', $payload['error']['code']);
        $this->assertFalse($payload['error']['retryable']);
    }

    public function test_invalid_argument_with_validation_details(): void
    {
        $response = $this->tool->invalidArgumentError('Validation failed.', [
            'name' => ['Name is required.'],
        ]);

        $payload = $this->decode($response);
        $this->assertSame('INVALID_ARGUMENT', $payload['error']['code']);
        $this->assertArrayHasKey('details', $payload['error']);
        $this->assertArrayHasKey('fields', $payload['error']['details']);
    }

    public function test_invalid_argument_without_details(): void
    {
        $response = $this->tool->invalidArgumentError('Bad input.');

        $payload = $this->decode($response);
        $this->assertArrayNotHasKey('details', $payload['error']);
    }

    public function test_resource_exhausted_with_retry_after(): void
    {
        $response = $this->tool->resourceExhaustedError('Rate limited.', 5000);

        $payload = $this->decode($response);
        $this->assertSame('RESOURCE_EXHAUSTED', $payload['error']['code']);
        $this->assertTrue($payload['error']['retryable']);
        $this->assertSame(5000, $payload['error']['retry_after_ms']);
    }

    public function test_failed_precondition(): void
    {
        $response = $this->tool->failedPreconditionError('Must be active first.');

        $payload = $this->decode($response);
        $this->assertSame('FAILED_PRECONDITION', $payload['error']['code']);
        $this->assertFalse($payload['error']['retryable']);
    }

    public function test_unavailable(): void
    {
        $response = $this->tool->unavailableError();

        $payload = $this->decode($response);
        $this->assertSame('UNAVAILABLE', $payload['error']['code']);
        $this->assertTrue($payload['error']['retryable']);
    }

    public function test_deadline_exceeded(): void
    {
        $response = $this->tool->deadlineExceededError();

        $payload = $this->decode($response);
        $this->assertSame('DEADLINE_EXCEEDED', $payload['error']['code']);
        $this->assertFalse($payload['error']['retryable']);
    }

    public function test_error_response_generic(): void
    {
        $response = $this->tool->errorResponse(ErrorCode::Internal, 'Oops');

        $payload = $this->decode($response);
        $this->assertSame('INTERNAL', $payload['error']['code']);
        $this->assertTrue($payload['error']['retryable']);
    }

    /**
     * @return array{error: array<string, mixed>}
     */
    private function decode(Response $response): array
    {
        $this->assertTrue($response->isError());

        $content = $response->content();
        $reflection = new \ReflectionObject($content);
        $prop = $reflection->getProperty('text');
        $prop->setAccessible(true);
        $text = $prop->getValue($content);

        $decoded = json_decode($text, true);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
