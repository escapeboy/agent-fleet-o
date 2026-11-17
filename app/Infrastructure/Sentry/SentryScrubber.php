<?php

namespace App\Infrastructure\Sentry;

use Sentry\Event;
use Sentry\EventHint;
use Sentry\UserDataBag;

/**
 * Last stop before an event leaves for Sentry. sentry-laravel 4 in its default ("legacy") mode sends request bodies,
 * page URLs with query strings, Referer/cookies/headers, Livewire component state and function arguments even with
 * send_default_pii=false. Instead of chasing each source, an event keeps only what debugging needs: the exception and
 * stack trace, tags, contexts set by this app, the request method plus URL path and (at most) the user id.
 *
 * Configured in config/sentry.php as array callables so the config stays cacheable.
 */
final class SentryScrubber
{
    public static function beforeSend(Event $event, ?EventHint $hint = null): Event
    {
        self::scrubRequest($event);
        $event->setBreadcrumb([]);
        $event->setExtra([]);

        // Frame vars hold function arguments (request payloads, Livewire snapshots, tokens). php.ini drops them
        // already (zend.exception_ignore_args); this does not rely on that.
        foreach ($event->getExceptions() as $exception) {
            foreach ($exception->getStacktrace()?->getFrames() ?? [] as $frame) {
                $frame->setVars([]);
            }
        }
        foreach ($event->getStacktrace()?->getFrames() ?? [] as $frame) {
            $frame->setVars([]);
        }

        $user = $event->getUser();
        if ($user !== null) {
            $event->setUser(UserDataBag::createFromUserIdentifier($user->getId() === null ? null : (string) $user->getId()));
        }

        return $event;
    }

    /** Performance transactions are kept; their request context gets the same scrub. */
    public static function beforeSendTransaction(Event $event, ?EventHint $hint = null): Event
    {
        self::scrubRequest($event);
        $event->setBreadcrumb([]);
        $event->setExtra([]);

        return $event;
    }

    private static function scrubRequest(Event $event): void
    {
        if ($event->getRequest() === []) {
            return;
        }

        $request = $event->getRequest();
        $url = $request['url'] ?? null;
        $event->setRequest(array_filter([
            'method' => $request['method'] ?? null,
            'url' => is_string($url) ? strtok($url, '?#') : null,
        ]));
    }
}
