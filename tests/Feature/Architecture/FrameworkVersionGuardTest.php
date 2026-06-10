<?php

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * Regression guard for the 2026-06-10 prod incident.
 *
 * A CVE `composer update` bumped laravel/framework v13.7.0 -> v13.15.0, which:
 *   - crash-looped Horizon queue workers ('stop-when-empty-for' option skew
 *     between framework and laravel/horizon 5.46),
 *   - broke partner-API sort with strtolower(SortDirection) on the Postgres
 *     query grammar (spatie/laravel-query-builder 7.3.0).
 * CI was green because the SQLite unit suite reproduces neither the Horizon
 * worker invocation nor the Postgres-specific sort path.
 *
 * Until laravel/horizon and spatie/laravel-query-builder are confirmed
 * compatible with framework >= 13.15 (and integration coverage exists for the
 * Horizon worker boot + partner sort on Postgres), the framework must stay
 * below 13.15.0. Raising MAX_EXCLUSIVE without doing that work re-opens the
 * incident.
 */
class FrameworkVersionGuardTest extends TestCase
{
    private const MAX_EXCLUSIVE = '13.15.0';

    public function test_laravel_framework_stays_below_known_bad_version(): void
    {
        $lock = json_decode(file_get_contents(base_path('composer.lock')), true);

        $framework = collect($lock['packages'])->firstWhere('name', 'laravel/framework');
        $this->assertNotNull($framework, 'laravel/framework missing from composer.lock');

        $version = ltrim($framework['version'], 'v');

        $this->assertTrue(
            version_compare($version, self::MAX_EXCLUSIVE, '<'),
            "laravel/framework {$version} is >= ".self::MAX_EXCLUSIVE.' — this range crash-loops '
            .'Horizon and breaks partner-API sort (2026-06-10 prod incident). Before raising the '
            .'ceiling, verify laravel/horizon + spatie/laravel-query-builder compatibility and add '
            .'Postgres integration coverage for the Horizon worker boot and partner sort paths.',
        );
    }
}
