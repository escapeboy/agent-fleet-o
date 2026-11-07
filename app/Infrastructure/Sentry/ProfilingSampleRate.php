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
 * config/sentry.php because the config file has no reliable way to know which
 * process will read it.
 *
 * `artisan config:cache` is itself a console command: it boots the app (so
 * applyTo() runs with runningInConsole = true) and then writes the whole,
 * already rewritten config to disk. To keep web requests profiling after
 * that, applyTo() never derives the rate from the value it may have
 * overwritten earlier. On first run it copies both configured rates into a
 * separate top-level config key (SOURCE_KEY) and always resolves from that
 * copy. The copy is cached along with everything else, so a web request
 * reading a cached config still finds the original HTTP rate, and a worker
 * still finds the console rate.
 */
final class ProfilingSampleRate
{
    /**
     * App-only key inside `sentry`. It must be removed before Sentry builds
     * its client: the SDK's options resolver rejects option names it does not
     * know.
     */
    public const CONSOLE_KEY = 'console_profiles_sample_rate';

    /**
     * Top-level config key holding the rates as configured, before any
     * rewrite. Kept outside `sentry` so it never reaches the Sentry SDK.
     */
    public const SOURCE_KEY = 'sentry_profiling_source';

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

        $source = $config->get(self::SOURCE_KEY);

        if (! is_array($source)) {
            $source = [
                'profiles_sample_rate' => $sentry['profiles_sample_rate'] ?? null,
                self::CONSOLE_KEY => $sentry[self::CONSOLE_KEY] ?? null,
            ];
            $config->set(self::SOURCE_KEY, $source);
        }

        $configured = $source['profiles_sample_rate'] ?? null;
        $consoleRate = $source[self::CONSOLE_KEY] ?? null;

        unset($sentry[self::CONSOLE_KEY]);

        $sentry['profiles_sample_rate'] = self::resolve(
            is_numeric($configured) ? (float) $configured : null,
            is_numeric($consoleRate) ? (float) $consoleRate : null,
            $runningInConsole,
        );

        $config->set('sentry', $sentry);
    }
}
