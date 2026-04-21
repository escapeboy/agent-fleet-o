<?php

declare(strict_types=1);

namespace App\Mcp;

enum ErrorCode: string
{
    case Unavailable = 'UNAVAILABLE';
    case PermissionDenied = 'PERMISSION_DENIED';
    case ResourceExhausted = 'RESOURCE_EXHAUSTED';
    case DeadlineExceeded = 'DEADLINE_EXCEEDED';
    case InvalidArgument = 'INVALID_ARGUMENT';
    case FailedPrecondition = 'FAILED_PRECONDITION';
    case NotFound = 'NOT_FOUND';
    case Internal = 'INTERNAL';

    public function isRetryable(): bool
    {
        return match ($this) {
            self::Unavailable,
            self::ResourceExhausted,
            self::Internal => true,
            self::PermissionDenied,
            self::DeadlineExceeded,
            self::InvalidArgument,
            self::FailedPrecondition,
            self::NotFound => false,
        };
    }

    public function httpStatus(): int
    {
        return match ($this) {
            self::Unavailable => 503,
            self::PermissionDenied => 403,
            self::ResourceExhausted => 429,
            self::DeadlineExceeded => 504,
            self::InvalidArgument => 400,
            self::FailedPrecondition => 412,
            self::NotFound => 404,
            self::Internal => 500,
        };
    }
}
