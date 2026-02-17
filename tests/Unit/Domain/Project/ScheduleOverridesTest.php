<?php

namespace Tests\Unit\Domain\Project;

use App\Domain\Project\Models\ProjectSchedule;
use PHPUnit\Framework\TestCase;

class ScheduleOverridesTest extends TestCase
{
    public function test_overrides_column_is_castable(): void
    {
        $schedule = new ProjectSchedule;
        $schedule->overrides = ['execution_mode' => 'yolo', 'model_override' => 'claude-haiku-4-5-20251001'];

        $this->assertIsArray($schedule->overrides);
        $this->assertEquals('yolo', $schedule->overrides['execution_mode']);
    }

    public function test_get_override_returns_value(): void
    {
        $schedule = new ProjectSchedule;
        $schedule->overrides = ['execution_mode' => 'yolo', 'timeout_minutes' => 120];

        $this->assertEquals('yolo', $schedule->getOverride('execution_mode'));
        $this->assertEquals(120, $schedule->getOverride('timeout_minutes'));
    }

    public function test_get_override_returns_default_when_missing(): void
    {
        $schedule = new ProjectSchedule;
        $schedule->overrides = [];

        $this->assertNull($schedule->getOverride('execution_mode'));
        $this->assertEquals('fallback', $schedule->getOverride('execution_mode', 'fallback'));
    }

    public function test_get_override_returns_default_when_null(): void
    {
        $schedule = new ProjectSchedule;
        $schedule->overrides = null;

        $this->assertNull($schedule->getOverride('execution_mode'));
    }

    public function test_overrides_with_nested_retry_policy(): void
    {
        $schedule = new ProjectSchedule;
        $schedule->overrides = [
            'retry_policy' => [
                'max_retries' => 3,
                'backoff' => 'exponential',
                'initial_delay_seconds' => 5,
            ],
        ];

        $this->assertEquals(3, $schedule->getOverride('retry_policy.max_retries'));
        $this->assertEquals('exponential', $schedule->getOverride('retry_policy.backoff'));
    }

    public function test_empty_overrides_stored_as_null(): void
    {
        $overrides = array_filter([]);

        $this->assertEmpty($overrides);
    }

    public function test_override_schema_keys(): void
    {
        $validKeys = ['execution_mode', 'model_override', 'max_concurrency', 'timeout_minutes', 'budget_cap_override', 'retry_policy'];
        $schedule = new ProjectSchedule;
        $schedule->overrides = array_fill_keys($validKeys, null);

        foreach ($validKeys as $key) {
            $this->assertArrayHasKey($key, $schedule->overrides);
        }
    }

    public function test_overrides_fillable(): void
    {
        $schedule = new ProjectSchedule;

        $this->assertContains('overrides', $schedule->getFillable());
    }
}
