<?php

namespace Tests\Unit\Domain\Tool\Exceptions;

use App\Domain\Tool\Exceptions\ResultAsAnswerException;
use Tests\TestCase;

class ResultAsAnswerExceptionTest extends TestCase
{
    public function test_exception_carries_tool_result(): void
    {
        $result = 'The search returned 42 results.';
        $exception = new ResultAsAnswerException($result, 'web_search');

        $this->assertEquals($result, $exception->toolResult);
        $this->assertEquals('web_search', $exception->toolName);
        $this->assertStringContainsString('web_search', $exception->getMessage());
    }

    public function test_exception_accepts_array_result(): void
    {
        $result = ['data' => [1, 2, 3], 'count' => 3];
        $exception = new ResultAsAnswerException($result, 'api_call');

        $this->assertEquals($result, $exception->toolResult);
        $this->assertIsArray($exception->toolResult);
    }

    public function test_exception_extends_runtime_exception(): void
    {
        $exception = new ResultAsAnswerException('result', 'tool');

        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }
}
