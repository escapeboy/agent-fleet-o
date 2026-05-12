<?php

declare(strict_types=1);

namespace App\Jobs\Middleware;

/**
 * Marker contract for jobs that declare explicit Sentry context.
 *
 * Jobs implementing this interface override the reflection-based default in
 * SentryContextJobMiddleware and provide the exact set of tags they want
 * propagated to Sentry on failure.
 *
 * Returned shape uses the standard FleetQ context keys (see
 * SentryContext::TAG_KEYS).
 */
interface HasSentryContext
{
    /**
     * @return array<string, mixed>
     */
    public function sentryContext(): array;
}
