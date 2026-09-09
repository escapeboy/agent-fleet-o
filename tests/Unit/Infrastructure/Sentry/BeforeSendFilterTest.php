<?php

namespace Tests\Unit\Infrastructure\Sentry;

use App\Infrastructure\Sentry\BeforeSendFilter;
use Predis\Connection\Resource\Exception\StreamInitException;
use Predis\Response\ServerException;
use RuntimeException;
use Sentry\Event;
use Sentry\EventHint;
use Tests\TestCase;

class BeforeSendFilterTest extends TestCase
{
    private function eventFor(\Throwable $exception): array
    {
        $event = Event::createEvent();
        $hint = EventHint::fromArray([
            'exception' => $exception,
        ]);

        return [$event, $hint];
    }

    public function test_drops_redis_loading_server_exception(): void
    {
        [$event, $hint] = $this->eventFor(new ServerException('LOADING Redis is loading the dataset in memory'));

        $result = BeforeSendFilter::filter($event, $hint);

        $this->assertNull($result);
    }

    public function test_keeps_other_predis_server_exceptions(): void
    {
        [$event, $hint] = $this->eventFor(new ServerException('WRONGTYPE Operation against a key holding the wrong kind of value'));

        $result = BeforeSendFilter::filter($event, $hint);

        $this->assertSame($event, $result);
    }

    public function test_keeps_redis_stream_init_exception(): void
    {
        // Regression guard: StreamInitException (connect-time failure) is a
        // different branch and must not be affected by the LOADING check.
        [$event, $hint] = $this->eventFor(new StreamInitException('Connection refused'));

        $result = BeforeSendFilter::filter($event, $hint);

        $this->assertNull($result);
    }

    public function test_keeps_unrelated_exceptions(): void
    {
        [$event, $hint] = $this->eventFor(new RuntimeException('Something unrelated broke'));

        $result = BeforeSendFilter::filter($event, $hint);

        $this->assertSame($event, $result);
    }

    public function test_returns_event_when_hint_has_no_exception(): void
    {
        $event = Event::createEvent();

        $this->assertSame($event, BeforeSendFilter::filter($event, null));
    }
}
