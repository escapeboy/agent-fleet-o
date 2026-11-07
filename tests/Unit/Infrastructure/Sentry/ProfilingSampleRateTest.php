<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Sentry;

use App\Infrastructure\Sentry\ProfilingSampleRate;
use Illuminate\Config\Repository;
use PHPUnit\Framework\TestCase;

class ProfilingSampleRateTest extends TestCase
{
    public function test_http_context_returns_configured_rate_unchanged(): void
    {
        $this->assertSame(1.0, ProfilingSampleRate::resolve(1.0, null, false));
        $this->assertSame(0.25, ProfilingSampleRate::resolve(0.25, 0.0, false));
        $this->assertNull(ProfilingSampleRate::resolve(null, 0.5, false));
    }

    public function test_console_context_disables_profiling_by_default(): void
    {
        $this->assertSame(0.0, ProfilingSampleRate::resolve(1.0, null, true));
        $this->assertSame(0.0, ProfilingSampleRate::resolve(1.0, 0.0, true));
    }

    public function test_console_context_uses_override_and_clamps_it(): void
    {
        $this->assertSame(0.05, ProfilingSampleRate::resolve(1.0, 0.05, true));
        $this->assertSame(1.0, ProfilingSampleRate::resolve(0.1, 3.0, true));
        $this->assertSame(0.0, ProfilingSampleRate::resolve(1.0, -0.5, true));
    }

    public function test_apply_to_rewrites_rate_in_console_context(): void
    {
        $config = new Repository(['sentry' => [
            'profiles_sample_rate' => 1.0,
            'console_profiles_sample_rate' => 0.0,
        ]]);

        ProfilingSampleRate::applyTo($config, true);

        $this->assertSame(0.0, $config->get('sentry.profiles_sample_rate'));
    }

    public function test_apply_to_leaves_rate_untouched_in_http_context(): void
    {
        $config = new Repository(['sentry' => [
            'profiles_sample_rate' => 1.0,
            'console_profiles_sample_rate' => 0.0,
        ]]);

        ProfilingSampleRate::applyTo($config, false);

        $this->assertSame(1.0, $config->get('sentry.profiles_sample_rate'));
    }

    public function test_apply_to_strips_the_custom_key_so_sentry_options_stay_valid(): void
    {
        // The Sentry SDK rejects unknown option keys, so the app-only key must
        // not reach Sentry's client builder in either context.
        foreach ([true, false] as $runningInConsole) {
            $config = new Repository(['sentry' => [
                'dsn' => null,
                'profiles_sample_rate' => 1.0,
                'console_profiles_sample_rate' => 0.1,
            ]]);

            ProfilingSampleRate::applyTo($config, $runningInConsole);

            $this->assertArrayNotHasKey('console_profiles_sample_rate', $config->get('sentry'));
            $this->assertArrayHasKey('dsn', $config->get('sentry'));
        }
    }

    public function test_apply_to_is_a_no_op_without_sentry_config(): void
    {
        $config = new Repository([]);

        ProfilingSampleRate::applyTo($config, true);

        $this->assertNull($config->get('sentry'));
    }
}
