<?php

declare(strict_types=1);

namespace Tests\Feature\Observability;

use Sentry\State\HubInterface;
use Tests\TestCase;

class SentryConsoleProfilingTest extends TestCase
{
    public function test_console_process_has_profiling_disabled_after_boot(): void
    {
        $this->assertTrue($this->app->runningInConsole());

        // config/sentry.php defaults console_profiles_sample_rate to 0.0;
        // ObservabilityServiceProvider swaps it in and drops the app-only key.
        $this->assertSame(0.0, config('sentry.profiles_sample_rate'));
        $this->assertArrayNotHasKey('console_profiles_sample_rate', config('sentry'));
    }

    public function test_sentry_client_is_built_with_the_console_rate(): void
    {
        $client = $this->app->make(HubInterface::class)->getClient();

        if ($client === null) {
            $this->markTestSkipped('Sentry builds no client in this environment.');
        }

        $this->assertSame(0.0, $client->getOptions()->getProfilesSampleRate());
    }
}
