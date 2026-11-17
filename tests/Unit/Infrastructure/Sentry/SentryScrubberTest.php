<?php

namespace Tests\Unit\Infrastructure\Sentry;

use App\Infrastructure\Sentry\BeforeSendFilter;
use App\Infrastructure\Sentry\SentryScrubber;
use RuntimeException;
use Sentry\Breadcrumb;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;
use Tests\TestCase;

class SentryScrubberTest extends TestCase
{
    private function transport(): TransportInterface
    {
        return new class implements TransportInterface
        {
            public array $events = [];

            public function send(Event $event): Result
            {
                $this->events[] = $event;

                return new Result(ResultStatus::success(), $event);
            }

            public function close(?int $timeout = null): Result
            {
                return new Result(ResultStatus::success());
            }
        };
    }

    private function client(TransportInterface $transport, bool $scrub)
    {
        $options = [
            'dsn' => 'https://public@sentry.invalid/1',
            'max_request_body_size' => $scrub ? config('sentry.max_request_body_size') : 'always',
            'default_integrations' => false,
        ];
        if ($scrub) {
            $options['before_send'] = config('sentry.before_send');
            $options['before_send_transaction'] = config('sentry.before_send_transaction');
        }

        return ClientBuilder::create($options)->setTransport($transport)->getClient();
    }

    private function leakyEvent(): Event
    {
        $event = Event::createEvent();
        $event->setMessage('boom');
        $event->setRequest([
            'method' => 'POST',
            'url' => 'https://app.example/livewire/update?q=SECRET-QUERY#SECRET-FRAGMENT',
            'query_string' => 'q=SECRET-QUERY',
            'data' => ['password' => 'SECRET-BODY'],
            'cookies' => ['session' => 'SECRET-COOKIE'],
            'headers' => ['Referer' => ['https://app.example/page?note=SECRET-REFERER'], 'Authorization' => ['Bearer SECRET-AUTH']],
            'env' => ['REMOTE_ADDR' => '203.0.113.9'],
        ]);
        $event->setBreadcrumb([
            new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'livewire.component', 'x', ['state' => 'SECRET-LIVEWIRE']),
            new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_HTTP, 'http', null, ['url' => 'https://api.example/x?token=SECRET-HTTP']),
        ]);
        $event->setExtra(['draft' => 'SECRET-EXTRA']);

        return $event;
    }

    private function throwWithArgs(object $client): void
    {
        $ignoreArgs = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0'); // worst case: arguments are in the backtrace
        try {
            (function (string $draft) {
                throw new RuntimeException('boom');
            })('SECRET-ARG');
        } catch (RuntimeException $e) {
            $client->captureException($e);
        } finally {
            ini_set('zend.exception_ignore_args', $ignoreArgs);
        }
    }

    public function test_control_without_scrubber_leaks(): void
    {
        $transport = $this->transport();
        $client = $this->client($transport, false);
        $client->captureEvent($this->leakyEvent());
        $this->throwWithArgs($client);

        $this->assertStringContainsString('SECRET-QUERY', serialize($transport->events[0]));
        $this->assertStringContainsString('SECRET-BODY', serialize($transport->events[0]));
        $this->assertStringContainsString('SECRET-ARG', serialize($transport->events[1]));
    }

    public function test_scrubbed_events_carry_nothing_sensitive(): void
    {
        $transport = $this->transport();
        $client = $this->client($transport, true);
        $client->captureEvent($this->leakyEvent());
        $this->throwWithArgs($client);

        $this->assertCount(2, $transport->events);
        $this->assertSame(
            ['method' => 'POST', 'url' => 'https://app.example/livewire/update'],
            $transport->events[0]->getRequest(),
        );
        $this->assertSame([], $transport->events[0]->getBreadcrumbs());
        $this->assertSame([], $transport->events[0]->getExtra());
        $frames = $transport->events[1]->getExceptions()[0]->getStacktrace()->getFrames();
        $this->assertNotEmpty($frames);
        foreach ($frames as $frame) {
            $this->assertSame([], $frame->getVars());
        }
        foreach ($transport->events as $event) {
            $this->assertStringNotContainsString('SECRET', serialize($event));
        }
    }

    public function test_noise_filter_still_drops_and_kept_events_are_scrubbed(): void
    {
        $dropped = BeforeSendFilter::filter(Event::createEvent(), \Sentry\EventHint::fromArray(['exception' => new RuntimeException('Webhook delivery failed')]));
        $this->assertNull($dropped);

        $kept = BeforeSendFilter::filter($this->leakyEvent(), null);
        $this->assertSame([], $kept->getBreadcrumbs());
        $this->assertSame('https://app.example/livewire/update', $kept->getRequest()['url']);
    }

    public function test_transactions_are_kept_but_scrubbed(): void
    {
        $event = $this->leakyEvent();
        $out = SentryScrubber::beforeSendTransaction($event);

        $this->assertSame(['method' => 'POST', 'url' => 'https://app.example/livewire/update'], $out->getRequest());
        $this->assertSame([], $out->getBreadcrumbs());
    }

    public function test_safe_defaults(): void
    {
        $this->assertSame('never', config('sentry.max_request_body_size'));
        $this->assertFalse(config('sentry.send_default_pii'));
        $this->assertFalse(config('sentry.breadcrumbs.livewire'));
        $this->assertFalse(config('sentry.breadcrumbs.http_client_requests'));
        $this->assertFalse(config('sentry.breadcrumbs.sql_bindings'));
        $this->assertFalse(config('sentry.tracing.http_client_requests'));
        $this->assertFalse(config('sentry.tracing.sql_bindings'));
        $this->assertSame([SentryScrubber::class, 'beforeSendTransaction'], config('sentry.before_send_transaction'));
    }

    public function test_production_php_ini_drops_call_arguments(): void
    {
        $this->assertStringContainsString('zend.exception_ignore_args = On', file_get_contents(base_path('docker/php/conf.d/security.ini')));
    }
}
