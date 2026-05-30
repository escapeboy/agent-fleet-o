<?php

namespace Tests\Unit\Infrastructure\Sentry;

use App\Infrastructure\Sentry\BeforeSendFilter;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Sentry\Event;
use Sentry\EventHint;
use Tests\TestCase;

class BeforeSendFilterWebhookTest extends TestCase
{
    #[Test]
    public function it_drops_partner_webhook_retry_noise(): void
    {
        $result = BeforeSendFilter::filter(
            Event::createEvent(),
            $this->hintFor(new RuntimeException('Webhook delivery failed with HTTP 500')),
        );

        $this->assertNull($result);
    }

    #[Test]
    public function it_keeps_unrelated_runtime_exceptions(): void
    {
        $event = Event::createEvent();

        $result = BeforeSendFilter::filter(
            $event,
            $this->hintFor(new RuntimeException('Something genuinely broke')),
        );

        $this->assertSame($event, $result);
    }

    private function hintFor(\Throwable $e): EventHint
    {
        $hint = new EventHint;
        $hint->exception = $e;

        return $hint;
    }
}
