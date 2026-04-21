<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp;

use App\Mcp\DeadlineContext;
use App\Mcp\Exceptions\DeadlineExceededException;
use Tests\TestCase;

class DeadlineContextTest extends TestCase
{
    public function test_fresh_context_is_not_set(): void
    {
        $ctx = new DeadlineContext;

        $this->assertFalse($ctx->isSet());
        $this->assertNull($ctx->remaining());
        $this->assertFalse($ctx->expired());
    }

    public function test_assert_not_expired_is_no_op_when_unset(): void
    {
        $ctx = new DeadlineContext;
        $ctx->assertNotExpired(); // should not throw

        $this->addToAssertionCount(1);
    }

    public function test_set_tracks_remaining_time(): void
    {
        $ctx = new DeadlineContext;
        $ctx->set(500);

        $this->assertTrue($ctx->isSet());
        $remaining = $ctx->remaining();
        $this->assertNotNull($remaining);
        $this->assertGreaterThan(0, $remaining);
        $this->assertLessThanOrEqual(500, $remaining);
    }

    public function test_set_clamps_below_minimum_to_100ms(): void
    {
        $ctx = new DeadlineContext;
        $ctx->set(5);

        $this->assertGreaterThanOrEqual(50, $ctx->remaining() ?? 0);
        $this->assertLessThanOrEqual(100, $ctx->remaining() ?? 0);
    }

    public function test_zero_deadline_is_clamped(): void
    {
        $ctx = new DeadlineContext;
        $ctx->set(0);

        $this->assertTrue($ctx->isSet());
        $this->assertGreaterThanOrEqual(50, $ctx->remaining() ?? 0);
    }

    public function test_expired_after_clamped_minimum_elapsed(): void
    {
        $ctx = new DeadlineContext;
        $ctx->set(100);

        usleep(150_000); // 150ms

        $this->assertTrue($ctx->expired());
        $this->assertSame(0, $ctx->remaining());
    }

    public function test_assert_not_expired_throws_after_expiry(): void
    {
        $ctx = new DeadlineContext;
        $ctx->set(100);

        usleep(150_000);

        $this->expectException(DeadlineExceededException::class);
        $ctx->assertNotExpired();
    }

    public function test_clear_resets_state(): void
    {
        $ctx = new DeadlineContext;
        $ctx->set(5000);
        $ctx->clear();

        $this->assertFalse($ctx->isSet());
        $this->assertNull($ctx->remaining());
    }

    public function test_singleton_binding_shares_state(): void
    {
        $a = app(DeadlineContext::class);
        $b = app(DeadlineContext::class);

        $this->assertSame($a, $b);

        $a->set(1000);
        $this->assertTrue($b->isSet());

        $a->clear();
        $this->assertFalse($b->isSet());
    }
}
