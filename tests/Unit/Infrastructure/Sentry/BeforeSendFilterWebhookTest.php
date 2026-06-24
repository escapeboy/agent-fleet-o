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

    #[Test]
    public function it_drops_no_available_providers(): void
    {
        $result = BeforeSendFilter::filter(
            Event::createEvent(),
            $this->hintFor(new RuntimeException('No available providers in fallback chain')),
        );

        $this->assertNull($result);
    }

    #[Test]
    public function it_drops_upstream_provider_billing_failures(): void
    {
        $result = BeforeSendFilter::filter(
            Event::createEvent(),
            $this->hintFor(new RuntimeException(
                'OpenRouter Insufficient Credits: Insufficient credits. This account never purchased credits.',
            )),
        );

        $this->assertNull($result);
    }

    #[Test]
    public function it_drops_agent_has_no_skills_step_failures(): void
    {
        $result = BeforeSendFilter::filter(
            Event::createEvent(),
            $this->hintFor(new RuntimeException('Step failed: Agent has no skills or tools assigned')),
        );

        $this->assertNull($result);
    }

    private function hintFor(\Throwable $e): EventHint
    {
        $hint = new EventHint;
        $hint->exception = $e;

        return $hint;
    }
}
