<?php

declare(strict_types=1);

namespace App\Infrastructure\Sentry;

use Illuminate\Contracts\Config\Repository;

/**
 * Picks the Sentry profiling sample rate for the current process.
 *
 * Long-running console processes (Horizon, queue:work, the scheduler) wrap
 * every job in a transaction; profiling each sampled one can run agent jobs
 * out of memory inside the profiler. In console context the regular
 * `sentry.profiles_sample_rate` is therefore replaced by
 * `sentry.console_profiles_sample_rate` (0.0 unless set via
 * SENTRY_CONSOLE_PROFILES_SAMPLE_RATE). HTTP requests keep the configured rate.
 *
 * This runs at runtime (ObservabilityServiceProvider::register) instead of in
 * config/sentry.php on purpose: `artisan config:cache` executes in the console,
 * so a runningInConsole() check inside the config file would bake the console
 * rate into the cached config and disable profiling for web requests too.
 */
final class ProfilingSampleRate
{
    /**
     * App-only key. It must be removed before Sentry builds its client: the
     * SDK's options resolver rejects option names it does not know.
     */
    public const CONSOLE_KEY = 'console_profiles_sample_rate';

    public static function resolve(?float $configured, ?float $consoleRate, bool $runningInConsole): ?float
    {
        if (! $runningInConsole) {
            return $configured;
        }

        return max(0.0, min(1.0, $consoleRate ?? 0.0));
    }

    public static function applyTo(Repository $config, bool $runningInConsole): void
    {
        $sentry = $config->get('sentry');

        if (! is_array($sentry)) {
            return;
        }

        $configured = $sentry['profiles_sample_rate'] ?? null;
        $consoleRate = $sentry[self::CONSOLE_KEY] ?? null;

        unset($sentry[self::CONSOLE_KEY]);

        $sentry['profiles_sample_rate'] = self::resolve(
            is_numeric($configured) ? (float) $configured : null,
            is_numeric($consoleRate) ? (float) $consoleRate : null,
            $runningInConsole,
        );

        $config->set('sentry', $sentry);
    }
}
