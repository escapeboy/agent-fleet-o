<?php

namespace Tests\Feature\Console;

use Tests\TestCase;

class OpcacheClearCommandTest extends TestCase
{
    public function test_command_runs_without_throwing_in_any_opcache_state(): void
    {
        // The CI runner may have OPcache disabled, restricted, or fully enabled.
        // The command must exit with a recognized status (0=SUCCESS, 1=FAILURE)
        // and never throw — `php artisan opcache:clear` runs unconditionally
        // from `scripts/deploy.sh`.
        $exitCode = $this->artisan('opcache:clear')->run();

        $this->assertContains(
            $exitCode,
            [0, 1],
            'opcache:clear must exit cleanly regardless of OPcache state, got exit code '.$exitCode,
        );
    }

    public function test_command_emits_skip_message_when_opcache_unavailable(): void
    {
        // Opcache isn't available in PHPUnit by default (OPcache disables itself
        // for CLI unless opcache.enable_cli=1). The command should warn and
        // succeed rather than fail.
        if (function_exists('opcache_reset') && function_exists('opcache_get_status') && @opcache_get_status(false) !== false) {
            $this->markTestSkipped('OPcache is enabled in this environment — covered by the integration test path.');
        }

        $this->artisan('opcache:clear')
            ->expectsOutputToContain('OPcache')
            ->assertSuccessful();
    }
}
