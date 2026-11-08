<?php

declare(strict_types=1);

namespace Tests\Feature\Observability;

use Sentry\State\HubInterface;
use Tests\TestCase;

class SentryConsoleProfilingTest extends TestCase
{
    private const ENV = 'SENTRY_CONSOLE_PROFILES_SAMPLE_RATE';

    private string|false $previousEnv;

    /**
     * Pin the console rate before the app boots so a value from .env or a
     * docker-compose export cannot change what these tests observe.
     */
    protected function setUp(): void
    {
        $this->previousEnv = getenv(self::ENV);
        $this->setEnv('0.0');

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->setEnv($this->previousEnv === false ? null : $this->previousEnv);
    }

    private function setEnv(?string $value): void
    {
        if ($value === null) {
            putenv(self::ENV);
            unset($_ENV[self::ENV], $_SERVER[self::ENV]);

            return;
        }

        putenv(self::ENV.'='.$value);
        $_ENV[self::ENV] = $value;
        $_SERVER[self::ENV] = $value;
    }

    public function test_console_process_has_profiling_disabled_after_boot(): void
    {
        $this->assertTrue($this->app->runningInConsole());

        // ObservabilityServiceProvider swaps in console_profiles_sample_rate
        // (pinned to 0.0 above) and drops the app-only key.
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
