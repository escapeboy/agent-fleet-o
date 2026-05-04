<?php

namespace Tests\Feature\Domain\Tool;

use App\Domain\Tool\Services\BuiltIn\BrowserHarnessHandler;
use Tests\TestCase;

class BrowserHarnessFlagTest extends TestCase
{
    public function test_handler_returns_disabled_error_when_flag_off(): void
    {
        config(['browser.harness_enabled' => false]);

        $result = app(BrowserHarnessHandler::class)->execute(['task' => 'do stuff'], teamId: 'any');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('disabled', $result['error']);
    }

    public function test_handler_rejects_empty_task(): void
    {
        config(['browser.harness_enabled' => true]);

        $result = app(BrowserHarnessHandler::class)->execute(['task' => '   '], teamId: 'any');

        $this->assertFalse($result['ok']);
        $this->assertSame('task required', $result['error']);
    }
}
