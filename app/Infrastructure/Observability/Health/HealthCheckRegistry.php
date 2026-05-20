<?php

declare(strict_types=1);

namespace App\Infrastructure\Observability\Health;

use Laravel\Horizon\Horizon;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Checks\CacheCheck;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Checks\DebugModeCheck;
use Spatie\Health\Checks\Checks\EnvironmentCheck;
use Spatie\Health\Checks\Checks\HorizonCheck;
use Spatie\Health\Checks\Checks\RedisCheck;
use Spatie\Health\Checks\Checks\ScheduleCheck;
use Spatie\Health\Checks\Checks\UsedDiskSpaceCheck;
use Spatie\Health\Facades\Health;

/**
 * Declarative health check registry — wraps spatie/laravel-health with the
 * checks FleetQ cares about.
 *
 * Registers in ObservabilityServiceProvider::boot(). The existing HealthPage
 * Livewire and `/api/v1/health` endpoint continue to compute their own
 * boutique signals (stuck experiments, circuit breakers, etc. — domain
 * concerns that don't fit a generic Health check). For the basic
 * infrastructure layer (DB / Redis / cache / queue / disk / env / debug-mode
 * / schedule freshness), this registry is now the single source of truth.
 *
 * Failing checks bubble through the Spatie Health facade into:
 *   - the spatie/laravel-health JSON endpoint (if mounted via Health::routes)
 *   - the Notifiable channels Spatie ships (email, Slack — opt-in via config)
 *
 * We deliberately do NOT mount Spatie's auto-routing on `/health` to avoid
 * colliding with the existing HealthPage Livewire. Callers should resolve
 * HealthCheckRegistry from the container and use it directly.
 */
final class HealthCheckRegistry
{
    /** @var array<int, Check>|null */
    private ?array $checks = null;

    public function register(): void
    {
        Health::checks($this->checks());
    }

    /**
     * @return array<int, Check>
     */
    public function checks(): array
    {
        return $this->checks ??= $this->buildChecks();
    }

    /**
     * @return array<int, Check>
     */
    private function buildChecks(): array
    {
        $checks = [
            DatabaseCheck::new()->name('postgres'),
            RedisCheck::new()->name('redis'),
            CacheCheck::new()->name('cache')->driver(config('cache.default', 'redis')),
            DebugModeCheck::new()->name('debug-mode'),
            UsedDiskSpaceCheck::new()
                ->name('disk-space')
                ->warnWhenUsedSpaceIsAbovePercentage(80)
                ->failWhenUsedSpaceIsAbovePercentage(95),
            ScheduleCheck::new()
                ->name('scheduler')
                ->heartbeatMaxAgeInMinutes(5),
        ];

        // Optional: HorizonCheck only if Horizon facade is bound.
        if (class_exists(Horizon::class)) {
            $checks[] = HorizonCheck::new()->name('horizon');
        }

        // EnvironmentCheck fails when APP_ENV != production in prod-shaped
        // deployments. We pin the expected env via the OBSERVABILITY_HEALTH_EXPECTED_ENV
        // env so dev / staging can keep their natural values.
        $expectedEnv = (string) config('observability.health.expected_env', '');
        if ($expectedEnv !== '') {
            $checks[] = EnvironmentCheck::new()->name('environment')->expectEnvironment($expectedEnv);
        }

        return $checks;
    }
}
