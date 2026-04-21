<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp;

use App\Domain\Budget\Exceptions\InsufficientBudgetException;
use App\Domain\Outbound\Exceptions\BlacklistedException;
use App\Domain\Outbound\Exceptions\RateLimitExceededException as OutboundRateLimitException;
use App\Domain\Signal\Exceptions\InvalidSignalTransitionException;
use App\Infrastructure\AI\Exceptions\BridgeTimeoutException;
use App\Infrastructure\AI\Exceptions\RateLimitExceededException as AiRateLimitException;
use App\Infrastructure\Git\Exceptions\GitAuthException;
use App\Infrastructure\Git\Exceptions\GitFileNotFoundException;
use App\Mcp\ErrorClassifier;
use App\Mcp\ErrorCode;
use App\Mcp\Exceptions\DeadlineExceededException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ErrorClassifierTest extends TestCase
{
    private ErrorClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new ErrorClassifier;
    }

    public function test_classifies_deadline_exceeded(): void
    {
        $result = $this->classifier->classify(DeadlineExceededException::afterMs(100, 50));

        $this->assertSame('DEADLINE_EXCEEDED', $result['code']);
        $this->assertFalse($result['retryable']);
    }

    public function test_classifies_bridge_timeout_as_deadline(): void
    {
        $result = $this->classifier->classify(new BridgeTimeoutException('req-abc', 30));

        $this->assertSame('DEADLINE_EXCEEDED', $result['code']);
    }

    public function test_classifies_authorization_exception_as_permission_denied(): void
    {
        $result = $this->classifier->classify(new AuthorizationException('no'));

        $this->assertSame('PERMISSION_DENIED', $result['code']);
        $this->assertFalse($result['retryable']);
    }

    public function test_classifies_access_denied_http(): void
    {
        $result = $this->classifier->classify(new AccessDeniedHttpException);

        $this->assertSame('PERMISSION_DENIED', $result['code']);
    }

    public function test_classifies_git_auth_as_permission_denied(): void
    {
        $result = $this->classifier->classify(new GitAuthException('bad token'));

        $this->assertSame('PERMISSION_DENIED', $result['code']);
    }

    public function test_classifies_throttle_as_resource_exhausted_with_retry_after(): void
    {
        $exception = new ThrottleRequestsException('Too many', null, ['Retry-After' => '42']);
        $result = $this->classifier->classify($exception);

        $this->assertSame('RESOURCE_EXHAUSTED', $result['code']);
        $this->assertTrue($result['retryable']);
        $this->assertSame(42000, $result['retry_after_ms']);
    }

    public function test_classifies_ai_rate_limit(): void
    {
        $result = $this->classifier->classify(new AiRateLimitException('limit'));

        $this->assertSame('RESOURCE_EXHAUSTED', $result['code']);
    }

    public function test_classifies_outbound_rate_limit(): void
    {
        $result = $this->classifier->classify(new OutboundRateLimitException('limit'));

        $this->assertSame('RESOURCE_EXHAUSTED', $result['code']);
    }

    public function test_classifies_insufficient_budget(): void
    {
        $result = $this->classifier->classify(new InsufficientBudgetException('out'));

        $this->assertSame('RESOURCE_EXHAUSTED', $result['code']);
    }

    public function test_classifies_model_not_found(): void
    {
        $result = $this->classifier->classify(new ModelNotFoundException);

        $this->assertSame('NOT_FOUND', $result['code']);
    }

    public function test_classifies_not_found_http(): void
    {
        $result = $this->classifier->classify(new NotFoundHttpException);

        $this->assertSame('NOT_FOUND', $result['code']);
    }

    public function test_classifies_git_file_not_found(): void
    {
        $result = $this->classifier->classify(new GitFileNotFoundException('missing'));

        $this->assertSame('NOT_FOUND', $result['code']);
    }

    public function test_classifies_validation_exception_with_details(): void
    {
        $validator = validator(['name' => ''], ['name' => 'required']);
        $exception = new ValidationException($validator);

        $result = $this->classifier->classify($exception);

        $this->assertSame('INVALID_ARGUMENT', $result['code']);
        $this->assertArrayHasKey('details', $result);
        $this->assertArrayHasKey('name', $result['details']);
    }

    public function test_classifies_blacklisted_as_failed_precondition(): void
    {
        $result = $this->classifier->classify(new BlacklistedException('blocked'));

        $this->assertSame('FAILED_PRECONDITION', $result['code']);
    }

    public function test_classifies_invalid_signal_transition(): void
    {
        $result = $this->classifier->classify(new InvalidSignalTransitionException('bad transition'));

        $this->assertSame('FAILED_PRECONDITION', $result['code']);
    }

    public function test_classifies_connection_exception_as_unavailable(): void
    {
        $result = $this->classifier->classify(new ConnectionException('down'));

        $this->assertSame('UNAVAILABLE', $result['code']);
        $this->assertTrue($result['retryable']);
    }

    public function test_classifies_unknown_exception_as_internal(): void
    {
        $result = $this->classifier->classify(new RuntimeException('oops'));

        $this->assertSame('INTERNAL', $result['code']);
        $this->assertTrue($result['retryable']);
        $this->assertSame('An internal error occurred. Check server logs for details.', $result['message']);
    }

    public function test_class_name_hint_catches_plan_limit_exceeded(): void
    {
        $exception = new class('plan limit') extends RuntimeException
        {
            public function getName(): string
            {
                return 'PlanLimitExceededException';
            }
        };

        // Anonymous classes have basename pattern class@anonymous\0... so we simulate a
        // real cloud exception shape by extending a named class instead.
        $namedException = new PlanLimitExceededFakeException('plan');

        $result = $this->classifier->classify($namedException);

        $this->assertSame('RESOURCE_EXHAUSTED', $result['code']);
    }

    public function test_class_name_hint_catches_timeout_exception(): void
    {
        $exception = new TimeoutFakeException('too slow');

        $result = $this->classifier->classify($exception);

        $this->assertSame('DEADLINE_EXCEEDED', $result['code']);
    }

    public function test_message_is_truncated_to_500_chars(): void
    {
        $longMessage = str_repeat('a', 1000);
        $result = $this->classifier->classify(new ModelNotFoundException($longMessage));

        $this->assertSame(500, mb_strlen($result['message']));
    }

    public function test_dynamic_registration_takes_precedence(): void
    {
        $this->classifier->register(RuntimeException::class, ErrorCode::FailedPrecondition);

        $result = $this->classifier->classify(new RuntimeException('oops'));

        $this->assertSame('FAILED_PRECONDITION', $result['code']);
    }

    public function test_internal_errors_have_safe_generic_message(): void
    {
        $result = $this->classifier->classify(new RuntimeException('secret internal detail'));

        $this->assertSame('An internal error occurred. Check server logs for details.', $result['message']);
        $this->assertStringNotContainsString('secret internal detail', $result['message']);
    }
}

class PlanLimitExceededFakeException extends RuntimeException {}
class TimeoutFakeException extends RuntimeException {}
