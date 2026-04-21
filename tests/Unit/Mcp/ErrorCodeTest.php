<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp;

use App\Mcp\ErrorCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ErrorCodeTest extends TestCase
{
    /**
     * @return array<string, array{0: ErrorCode, 1: bool, 2: int}>
     */
    public static function codeMatrixProvider(): array
    {
        return [
            'unavailable' => [ErrorCode::Unavailable, true, 503],
            'permission_denied' => [ErrorCode::PermissionDenied, false, 403],
            'resource_exhausted' => [ErrorCode::ResourceExhausted, true, 429],
            'deadline_exceeded' => [ErrorCode::DeadlineExceeded, false, 504],
            'invalid_argument' => [ErrorCode::InvalidArgument, false, 400],
            'failed_precondition' => [ErrorCode::FailedPrecondition, false, 412],
            'not_found' => [ErrorCode::NotFound, false, 404],
            'internal' => [ErrorCode::Internal, true, 500],
        ];
    }

    #[DataProvider('codeMatrixProvider')]
    public function test_code_has_retryable_and_http_status(ErrorCode $code, bool $retryable, int $status): void
    {
        $this->assertSame($retryable, $code->isRetryable(), "isRetryable for {$code->value}");
        $this->assertSame($status, $code->httpStatus(), "httpStatus for {$code->value}");
    }

    public function test_all_codes_are_serializable_as_grpc_names(): void
    {
        $expected = [
            'UNAVAILABLE',
            'PERMISSION_DENIED',
            'RESOURCE_EXHAUSTED',
            'DEADLINE_EXCEEDED',
            'INVALID_ARGUMENT',
            'FAILED_PRECONDITION',
            'NOT_FOUND',
            'INTERNAL',
        ];

        $actual = array_map(fn (ErrorCode $c) => $c->value, ErrorCode::cases());

        $this->assertSame($expected, $actual);
    }
}
