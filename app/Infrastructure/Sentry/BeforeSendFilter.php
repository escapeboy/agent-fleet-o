<?php

namespace App\Infrastructure\Sentry;

use App\Domain\Shared\Exceptions\AiAccessUnavailableException;
use ErrorException;
use Illuminate\Database\QueryException;
use Illuminate\Queue\MaxAttemptsExceededException;
use Sentry\Event;
use Sentry\EventHint;
use Symfony\Component\Console\Exception\RuntimeException as SymfonyConsoleRuntimeException;

/**
 * Sentry before_send hook. Configured in config/sentry.php as an array
 * callable ([self::class, 'filter']) so the surrounding config can still be
 * cached by `php artisan config:cache` — a closure literal can't be
 * serialized (Closure::__set_state error).
 *
 * Runs at Sentry SDK level AFTER Laravel's reportable() callbacks. Returning
 * null drops the event before transport. This is the only hook where
 * message-pattern filters reliably win against Sentry\Laravel\Integration's
 * auto-capture path.
 *
 * Class-based filters (OAuthServerException, VpsLocalAgentException,
 * CommandNotFoundException) stay in bootstrap/app.php's dontReport() — those
 * are checked by Laravel BEFORE any reportable callback or before_send fires
 * and are slightly cheaper.
 */
final class BeforeSendFilter
{
    public static function filter(Event $event, ?EventHint $hint = null): ?Event
    {
        if ($hint === null || $hint->exception === null) {
            return $event;
        }

        $e = $hint->exception;
        $msg = $e->getMessage();

        // AiAccessUnavailableException: BYOK team with no key / no platform
        // entitlement. It is in bootstrap/app.php's dontReport(), but Sentry's
        // queue integration auto-captures unhandled job exceptions on a path
        // that bypasses dontReport() — so it still floods Sentry from Horizon
        // workers (#938/#880/#881). before_send is the only hook that reliably
        // wins against that path. Expected backpressure, not a defect.
        if ($e instanceof AiAccessUnavailableException) {
            return null;
        }

        // SQLSTATE[08006] — postgres connection_failure on container restart race.
        if ($e instanceof QueryException && str_contains($msg, 'SQLSTATE[08006]')) {
            return null;
        }

        // Anthropic auth_error / Prism 401 — FallbackAiGateway::isAuthError already
        // routes around it without recording a circuit-breaker failure.
        if (str_contains($msg, 'authentication_error') && str_contains($msg, 'x-api-key')) {
            return null;
        }

        // routes-v7.php TOCTOU during `php artisan route:cache` — deploy.sh retries
        // 3x; remaining noise is concurrent HTTP requests hitting the gap.
        if ($e instanceof ErrorException
            && str_contains($msg, 'routes-v7.php')
            && str_contains($msg, 'Failed to open stream')) {
            return null;
        }

        // Deprecated artisan flags from autonomous agents using stale knowledge.
        if ($e instanceof SymfonyConsoleRuntimeException
            && (str_contains($msg, 'option does not exist')
                || str_contains($msg, 'argument does not exist'))) {
            return null;
        }

        // RunSentryWatchdogJob max-attempts — known-slow batch background work.
        // Unstamped signals are re-picked up by the next scheduled tick.
        if ($e instanceof MaxAttemptsExceededException
            && str_contains($msg, 'RunSentryWatchdogJob')) {
            return null;
        }

        // "Cannot modify header information" only on direct-IP probes (host
        // is the raw VPS IP, not a domain) — security scanners.
        if ($e instanceof ErrorException && str_contains($msg, 'Cannot modify header information')) {
            $host = request()?->getHttpHost();
            if ($host === null || filter_var($host, FILTER_VALIDATE_IP) !== false) {
                return null;
            }
        }

        // Partner webhook delivered to a failing remote endpoint (non-2xx) on a
        // non-final attempt. DeliverPartnerWebhookJob rethrows to trigger the
        // queue backoff; the remote is failing, not FleetQ. Permanent failure
        // (attempts exhausted) is recorded via Log::warning, not this path.
        if (str_contains($msg, 'Webhook delivery failed')) {
            return null;
        }

        // No usable provider for a team mid-run (BYOK key removed / bridge
        // disconnected / budget exhausted after the experiment was created).
        // FallbackAiGateway has already exhausted the chain; the experiment is
        // paused/failed cleanly. Expected, not a FleetQ defect. (#840/#830)
        if (str_contains($msg, 'No available providers in fallback chain')) {
            return null;
        }

        // Upstream provider billing failure on a team's BYOK key (e.g.
        // "OpenRouter Insufficient Credits: This account never purchased
        // credits"). The provider rejected the call, not FleetQ — the team must
        // top up its own account. (#867)
        if (str_contains($msg, 'Insufficient Credits')
            || str_contains($msg, 'purchased credits')) {
            return null;
        }

        // Playbook step references an agent with no skills/tools — a team
        // configuration mistake surfaced in the experiment log/UI, not a code
        // defect. (#819/#821/#818/#820)
        if (str_contains($msg, 'Agent has no skills or tools assigned')) {
            return null;
        }

        // Corrupt/non-image upload — spatie medialibrary's queued conversion
        // can't decode it (imagecreatefromstring failed). The original upload
        // is unaffected; only the derived thumbnail is skipped. Bad user input,
        // not a FleetQ defect. (#815)
        if (str_contains($msg, 'Could not load image at path')) {
            return null;
        }

        // disposable-email weekly list refresh: the bundled fetcher uses
        // file_get_contents(https://) but prod sets allow_url_fopen=0 for
        // hardening. The package keeps serving its previously-stored list, so
        // this is a degraded-but-safe weekly warning, not a defect. (#825)
        if (str_contains($msg, 'allow_url_fopen=0')) {
            return null;
        }

        return $event;
    }
}
