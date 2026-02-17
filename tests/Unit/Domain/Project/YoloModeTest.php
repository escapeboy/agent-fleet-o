<?php

namespace Tests\Unit\Domain\Project;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Project\Enums\ProjectExecutionMode;
use PHPUnit\Framework\TestCase;

class YoloModeTest extends TestCase
{
    public function test_yolo_enum_exists(): void
    {
        $mode = ProjectExecutionMode::Yolo;

        $this->assertEquals('yolo', $mode->value);
        $this->assertEquals('YOLO', $mode->label());
        $this->assertTrue($mode->skipsTesting());
    }

    public function test_autonomous_does_not_skip_testing(): void
    {
        $mode = ProjectExecutionMode::Autonomous;

        $this->assertFalse($mode->skipsTesting());
    }

    public function test_watcher_does_not_skip_testing(): void
    {
        $mode = ProjectExecutionMode::Watcher;

        $this->assertFalse($mode->skipsTesting());
    }

    public function test_yolo_icon_and_color(): void
    {
        $mode = ProjectExecutionMode::Yolo;

        $this->assertNotEmpty($mode->icon());
        $this->assertStringContainsString('amber', $mode->color());
    }

    public function test_experiment_is_yolo_mode_with_yolo_constraint(): void
    {
        $experiment = new Experiment;
        $experiment->constraints = ['execution_mode' => 'yolo'];

        $this->assertTrue($experiment->isYoloMode());
    }

    public function test_experiment_is_not_yolo_mode_by_default(): void
    {
        $experiment = new Experiment;
        $experiment->constraints = [];

        $this->assertFalse($experiment->isYoloMode());
    }
}
