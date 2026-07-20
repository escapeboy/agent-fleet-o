<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shared;

use App\Domain\Shared\Notifications\WelcomeNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use PHPUnit\Framework\TestCase;

/**
 * Mail notifications sent on a request path must be queued.
 *
 * During the 2026-07-06 mail incident a synchronous send turned stale SMTP
 * credentials into a request-time retry storm against the mail host. This
 * guard exists because the original fix survived only as an uncommitted
 * patch on the production box and would have been silently reverted by the
 * next deploy.
 */
class QueuedMailNotificationsTest extends TestCase
{
    public function test_welcome_notification_is_queued(): void
    {
        $this->assertTrue(
            is_subclass_of(WelcomeNotification::class, ShouldQueue::class),
            'WelcomeNotification must implement ShouldQueue — a synchronous send blocks registration on the SMTP round-trip.',
        );
    }
}
