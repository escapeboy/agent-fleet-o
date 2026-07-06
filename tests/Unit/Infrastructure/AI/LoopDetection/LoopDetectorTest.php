<?php

namespace Tests\Unit\Infrastructure\AI\LoopDetection;

use App\Infrastructure\AI\LoopDetection\Enums\LoopSignalType;
use App\Infrastructure\AI\LoopDetection\LoopDetector;
use Tests\Support\LoopDetection\ArrayTurnHistoryStore;
use Tests\TestCase;

class LoopDetectorTest extends TestCase
{
    private function detector(): LoopDetector
    {
        return new LoopDetector(new ArrayTurnHistoryStore);
    }

    public function test_disabled_never_trips(): void
    {
        config(['loop_detection.enabled' => false]);
        $detector = $this->detector();

        for ($i = 0; $i < 10; $i++) {
            $signal = $detector->inspectTurn('exp:1', 'same prompt', 'same output', 1000.0 + $i);
            $this->assertFalse($signal->tripped());
        }
    }

    public function test_semantic_loop_trips_after_min_turns(): void
    {
        config([
            'loop_detection.enabled' => true,
            'loop_detection.similarity.threshold' => 0.9,
            'loop_detection.similarity.min_turns' => 3,
            'loop_detection.velocity.max_turns' => 100,
            'loop_detection.stall.repeat_threshold' => 100,
        ]);
        $detector = $this->detector();
        $prompt = 'summarize the quarterly earnings report for acme corp';

        $this->assertFalse($detector->inspectTurn('exp:1', $prompt, 'out-1', 1000.0)->tripped());
        $this->assertFalse($detector->inspectTurn('exp:1', $prompt, 'out-2', 1001.0)->tripped());

        $signal = $detector->inspectTurn('exp:1', $prompt, 'out-3', 1002.0);

        $this->assertSame(LoopSignalType::SemanticLoop, $signal->type);
    }

    public function test_varied_prompts_do_not_trip_semantic(): void
    {
        config([
            'loop_detection.enabled' => true,
            'loop_detection.similarity.threshold' => 0.9,
            'loop_detection.similarity.min_turns' => 3,
            'loop_detection.velocity.max_turns' => 100,
            'loop_detection.stall.repeat_threshold' => 100,
        ]);
        $detector = $this->detector();

        $this->assertFalse($detector->inspectTurn('exp:1', 'analyze customer churn drivers', 'a', 1000.0)->tripped());
        $this->assertFalse($detector->inspectTurn('exp:1', 'draft a pricing proposal for europe', 'b', 1001.0)->tripped());
        $this->assertFalse($detector->inspectTurn('exp:1', 'write release notes for version nine', 'c', 1002.0)->tripped());
    }

    public function test_runaway_velocity_trips(): void
    {
        config([
            'loop_detection.enabled' => true,
            'loop_detection.velocity.max_turns' => 3,
            'loop_detection.velocity.window_seconds' => 60,
            'loop_detection.similarity.min_turns' => 100,
            'loop_detection.stall.repeat_threshold' => 100,
        ]);
        $detector = $this->detector();

        $this->assertFalse($detector->inspectTurn('exp:1', 'p1', 'o1', 1000.0)->tripped());
        $this->assertFalse($detector->inspectTurn('exp:1', 'p2', 'o2', 1001.0)->tripped());
        $this->assertFalse($detector->inspectTurn('exp:1', 'p3', 'o3', 1002.0)->tripped());

        $signal = $detector->inspectTurn('exp:1', 'p4', 'o4', 1003.0);

        $this->assertSame(LoopSignalType::Runaway, $signal->type);
    }

    public function test_stall_trips_on_repeated_output(): void
    {
        config([
            'loop_detection.enabled' => true,
            'loop_detection.stall.repeat_threshold' => 3,
            'loop_detection.velocity.max_turns' => 100,
            'loop_detection.similarity.min_turns' => 100,
        ]);
        $detector = $this->detector();

        $this->assertFalse($detector->inspectTurn('exp:1', 'p1', 'IDENTICAL', 1000.0)->tripped());
        $this->assertFalse($detector->inspectTurn('exp:1', 'p2', 'IDENTICAL', 1001.0)->tripped());

        $signal = $detector->inspectTurn('exp:1', 'p3', 'IDENTICAL', 1002.0);

        $this->assertSame(LoopSignalType::Stall, $signal->type);
    }

    public function test_contexts_are_isolated(): void
    {
        config([
            'loop_detection.enabled' => true,
            'loop_detection.stall.repeat_threshold' => 2,
            'loop_detection.velocity.max_turns' => 100,
            'loop_detection.similarity.min_turns' => 100,
        ]);
        $detector = $this->detector();

        $detector->inspectTurn('exp:A', 'p', 'SAME', 1000.0);
        // Different context should not see exp:A's history.
        $this->assertFalse($detector->inspectTurn('exp:B', 'p', 'SAME', 1001.0)->tripped());
    }
}
