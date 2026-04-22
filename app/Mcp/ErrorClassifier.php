<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Domain\Budget\Exceptions\InsufficientBudgetException;
use App\Domain\Crew\Exceptions\CyclicDependencyException;
use App\Domain\Crew\Exceptions\MaxDelegationDepthExceededException;
use App\Domain\Outbound\Exceptions\BlacklistedException as OutboundBlacklistedException;
use App\Domain\Outbound\Exceptions\RateLimitExceededException as OutboundRateLimitException;
use App\Domain\Signal\Exceptions\InvalidSignalTransitionException;
use App\Infrastructure\AI\Exceptions\BridgeTimeoutException;
use App\Infrastructure\AI\Exceptions\ModelNotAllowedException;
use App\Infrastructure\AI\Exceptions\RateLimitExceededException as AiRateLimitException;
use App\Infrastructure\Git\Exceptions\GitAuthException;
use App\Infrastructure\Git\Exceptions\GitFileNotFoundException;
use App\Mcp\Exceptions\DeadlineExceededException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

class ErrorClassifier
{
    /**
     * Map of exception class (FQN) to ErrorCode.
     * Order matters — more specific classes should come first.
     * Parent classes are matched by is_a() if the exact class isn't listed.
     *
     * @var array<class-string, ErrorCode>
     */
    private const EXCEPTION_MAP = [
        DeadlineExceededException::class => ErrorCode::DeadlineExceeded,
        BridgeTimeoutException::class => ErrorCode::DeadlineExceeded,

        AuthorizationException::class => ErrorCode::PermissionDenied,
        AccessDeniedHttpException::class => ErrorCode::PermissionDenied,
        GitAuthException::class => ErrorCode::PermissionDenied,
        ModelNotAllowedException::class => ErrorCode::PermissionDenied,

        ThrottleRequestsException::class => ErrorCode::ResourceExhausted,
        TooManyRequestsHttpException::class => ErrorCode::ResourceExhausted,
        AiRateLimitException::class => ErrorCode::ResourceExhausted,
        OutboundRateLimitException::class => ErrorCode::ResourceExhausted,
        InsufficientBudgetException::class => ErrorCode::ResourceExhausted,

        ModelNotFoundException::class => ErrorCode::NotFound,
        NotFoundHttpException::class => ErrorCode::NotFound,
        GitFileNotFoundException::class => ErrorCode::NotFound,

        ValidationException::class => ErrorCode::InvalidArgument,

        OutboundBlacklistedException::class => ErrorCode::FailedPrecondition,
        InvalidSignalTransitionException::class => ErrorCode::FailedPrecondition,
        MaxDelegationDepthExceededException::class => ErrorCode::FailedPrecondition,
        CyclicDependencyException::class => ErrorCode::FailedPrecondition,

        ConnectionException::class => ErrorCode::Unavailable,
    ];

    /**
     * Optional cloud/plugin exception classes discovered at runtime.
     * Allows cloud edition exceptions (PlanLimitExceededException) to register
     * without a hard dependency.
     *
     * @var array<class-string, ErrorCode>
     */
    private array $dynamicMap = [];

    public function register(string $exceptionClass, ErrorCode $code): void
    {
        $this->dynamicMap[$exceptionClass] = $code;
    }

    /**
     * Classify an exception into a structured payload.
     *
     * @return array{code: string, message: string, retryable: bool, retry_after_ms?: int, details?: array<mixed>}
     */
    public function classify(Throwable $e): array
    {
        $code = $this->codeFor($e);

        $payload = [
            'code' => $code->value,
            'message' => $this->safeMessage($e, $code),
            'retryable' => $code->isRetryable(),
        ];

        if ($retryAfter = $this->retryAfterMs($e)) {
            $payload['retry_after_ms'] = $retryAfter;
        }

        if ($code === ErrorCode::InvalidArgument && $e instanceof ValidationException) {
            $payload['details'] = $e->errors();
        }

        return $payload;
    }

    private function codeFor(Throwable $e): ErrorCode
    {
        foreach ($this->dynamicMap as $class => $code) {
            if ($e instanceof $class) {
                return $code;
            }
        }

        foreach (self::EXCEPTION_MAP as $class => $code) {
            if ($e instanceof $class) {
                return $code;
            }
        }

        $classNameHint = static::classNameHint($e);

        if ($classNameHint !== null) {
            return $classNameHint;
        }

        return ErrorCode::Internal;
    }

    /**
     * Fallback classification by class name substring — covers cloud-only
     * exceptions that aren't imported here (e.g., PlanLimitExceededException).
     */
    private static function classNameHint(Throwable $e): ?ErrorCode
    {
        $short = class_basename($e);

        return match (true) {
            str_contains($short, 'PlanLimit'),
            str_contains($short, 'QuotaExceeded'),
            str_contains($short, 'RateLimit') => ErrorCode::ResourceExhausted,
            str_contains($short, 'NotFound') => ErrorCode::NotFound,
            str_contains($short, 'Unauthorized'),
            str_contains($short, 'Forbidden'),
            str_contains($short, 'AccessDenied') => ErrorCode::PermissionDenied,
            str_contains($short, 'Timeout'),
            str_contains($short, 'DeadlineExceeded') => ErrorCode::DeadlineExceeded,
            str_contains($short, 'Connection'),
            str_contains($short, 'Unavailable') => ErrorCode::Unavailable,
            str_contains($short, 'InvalidState'),
            str_contains($short, 'InvalidTransition'),
            str_contains($short, 'Precondition') => ErrorCode::FailedPrecondition,
            default => null,
        };
    }

    /**
     * Returns a message safe to surface to the MCP client.
     *
     * Internal errors AND connection failures use a generic message to avoid
     * leaking stack trace hints or internal topology (host:port, service names).
     * ModelNotFoundException is scrubbed to hide FQN + primary-key shape.
     */
    private function safeMessage(Throwable $e, ErrorCode $code): string
    {
        if ($code === ErrorCode::Internal) {
            return 'An internal error occurred. Check server logs for details.';
        }

        if ($e instanceof ConnectionException) {
            return 'Upstream service unavailable.';
        }

        if ($e instanceof ModelNotFoundException) {
            return 'The requested resource was not found.';
        }

        $message = $e->getMessage();

        if ($message === '') {
            return $code->value;
        }

        return mb_substr($message, 0, 500);
    }

    private function retryAfterMs(Throwable $e): ?int
    {
        if ($e instanceof ThrottleRequestsException) {
            $retryAfter = $e->getHeaders()['Retry-After'] ?? null;

            if ($retryAfter !== null && is_numeric($retryAfter)) {
                return (int) $retryAfter * 1000;
            }
        }

        return null;
    }
}
