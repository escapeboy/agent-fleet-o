<?php

namespace Tests\Unit\Infrastructure\Sentry;

use App\Domain\Shared\Exceptions\AiAccessUnavailableException;
use App\Infrastructure\Sentry\BeforeSendFilter;
use PHPUnit\Framework\Attributes\Test;
use Predis\Connection\ConnectionException;
use Predis\Connection\Parameters;
use Predis\Connection\Resource\Exception\StreamInitException;
use Predis\Connection\StreamConnection;
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

    #[Test]
    public function it_drops_ai_access_unavailable_from_queue_workers(): void
    {
        $result = BeforeSendFilter::filter(
            Event::createEvent(),
            $this->hintFor(AiAccessUnavailableException::forTeam()),
        );

        $this->assertNull($result);
    }

    #[Test]
    public function it_drops_redis_connect_failures_from_the_restart_race(): void
    {
        $result = BeforeSendFilter::filter(
            Event::createEvent(),
            $this->hintFor(new StreamInitException('Connection refused [tcp://agent-fleet-redis:6379]')),
        );

        $this->assertNull($result);
    }

    #[Test]
    public function it_drops_prometheus_redis_connect_failures(): void
    {
        $result = BeforeSendFilter::filter(
            Event::createEvent(),
            $this->hintFor(new RuntimeException(
                "Can't connect to Redis server. php_network_getaddresses: getaddrinfo for agent-fleet-redis failed",
            )),
        );

        $this->assertNull($result);
    }

    #[Test]
    public function it_keeps_redis_read_errors_on_established_connections(): void
    {
        // The restart-race filter must not swallow this class — a read error on
        // an open connection is a different defect (fixed separately in 16c2b9f2).
        $event = Event::createEvent();

        $result = BeforeSendFilter::filter(
            $event,
            $this->hintFor(new ConnectionException(
                new StreamConnection(new Parameters(['host' => 'agent-fleet-redis'])),
                'read error on connection to agent-fleet-redis:6379',
            )),
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
