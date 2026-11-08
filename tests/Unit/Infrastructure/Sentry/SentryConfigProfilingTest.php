<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Sentry;

use Tests\TestCase;

class SentryConfigProfilingTest extends TestCase
{
    private const ENV = 'SENTRY_CONSOLE_PROFILES_SAMPLE_RATE';

    private string|false $previous;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previous = getenv(self::ENV);
    }

    protected function tearDown(): void
    {
        $this->setEnv($this->previous === false ? null : $this->previous);
        parent::tearDown();
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

    private function loadConfig(): array
    {
        return require base_path('config/sentry.php');
    }

    public function test_console_rate_defaults_to_zero_when_env_unset(): void
    {
        $this->setEnv(null);

        $this->assertSame(0.0, $this->loadConfig()['console_profiles_sample_rate']);
    }

    public function test_console_rate_reads_env_override(): void
    {
        $this->setEnv('0.1');

        $this->assertSame(0.1, $this->loadConfig()['console_profiles_sample_rate']);
    }

    public function test_config_file_does_not_branch_on_console_context(): void
    {
        // config:cache runs in the console, so a runningInConsole() check here
        // would bake the console rate into web requests too.
        $source = (string) file_get_contents(base_path('config/sentry.php'));

        $this->assertStringNotContainsString('runningInConsole', $source);
    }
}
