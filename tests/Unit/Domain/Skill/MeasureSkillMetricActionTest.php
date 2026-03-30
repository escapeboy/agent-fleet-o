<?php

namespace Tests\Unit\Domain\Skill;

use App\Domain\Skill\Actions\MeasureSkillMetricAction;
use App\Domain\Skill\Exceptions\MetricExtractionException;
use App\Domain\Skill\Models\SkillExecution;
use Tests\TestCase;

class MeasureSkillMetricActionTest extends TestCase
{
    private MeasureSkillMetricAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = new MeasureSkillMetricAction;
    }

    private function makeExecution(mixed $output, ?int $durationMs = null): SkillExecution
    {
        $execution = new SkillExecution;
        $execution->output = $output;
        $execution->duration_ms = $durationMs;

        return $execution;
    }

    public function test_extracts_latency_ms(): void
    {
        $execution = $this->makeExecution('result', durationMs: 250);

        $value = $this->action->execute($execution, 'latency_ms');

        $this->assertEquals(250.0, $value);
    }

    public function test_latency_ms_throws_when_null(): void
    {
        $execution = $this->makeExecution('result', durationMs: null);

        $this->expectException(MetricExtractionException::class);
        $this->action->execute($execution, 'latency_ms');
    }

    public function test_extracts_output_length(): void
    {
        $execution = $this->makeExecution('hello world foo bar');

        $value = $this->action->execute($execution, 'output_length');

        $this->assertEquals(4.0, $value);
    }

    public function test_extracts_json_path(): void
    {
        $execution = $this->makeExecution(['score' => 0.85, 'meta' => ['accuracy' => 0.92]]);

        $this->assertEquals(0.85, $this->action->execute($execution, 'json:score'));
        $this->assertEquals(0.92, $this->action->execute($execution, 'json:meta.accuracy'));
    }

    public function test_json_path_throws_when_not_found(): void
    {
        $execution = $this->makeExecution(['score' => 0.85]);

        $this->expectException(MetricExtractionException::class);
        $this->action->execute($execution, 'json:missing.path');
    }

    public function test_json_path_throws_when_not_numeric(): void
    {
        $execution = $this->makeExecution(['label' => 'good']);

        $this->expectException(MetricExtractionException::class);
        $this->action->execute($execution, 'json:label');
    }

    public function test_extracts_regex_pattern(): void
    {
        $execution = $this->makeExecution('Score: 0.9341 achieved');

        $value = $this->action->execute($execution, 'regex:/Score: (\d+\.\d+)/');

        $this->assertEqualsWithDelta(0.9341, $value, 0.0001);
    }

    public function test_regex_throws_when_no_match(): void
    {
        $execution = $this->makeExecution('no score here');

        $this->expectException(MetricExtractionException::class);
        $this->action->execute($execution, 'regex:/Score: (\d+\.\d+)/');
    }

    public function test_unknown_metric_throws(): void
    {
        $execution = $this->makeExecution('result');

        $this->expectException(MetricExtractionException::class);
        $this->action->execute($execution, 'unknown_metric');
    }
}
