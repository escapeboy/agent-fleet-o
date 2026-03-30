<?php

namespace Tests\Unit\Domain\Skill;

use App\Domain\Skill\Actions\ComputeComplexityDeltaAction;
use App\Domain\Skill\Models\SkillVersion;
use Tests\TestCase;

class ComputeComplexityDeltaActionTest extends TestCase
{
    private ComputeComplexityDeltaAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = new ComputeComplexityDeltaAction;
    }

    private function makeVersion(string $template): SkillVersion
    {
        $version = new SkillVersion;
        $version->configuration = ['prompt_template' => $template];

        return $version;
    }

    public function test_returns_zero_for_identical_templates(): void
    {
        $v = $this->makeVersion('hello world foo bar');

        $this->assertEquals(0, $this->action->execute($v, $v));
    }

    public function test_positive_delta_when_candidate_is_longer(): void
    {
        $baseline = $this->makeVersion('short prompt');
        $candidate = $this->makeVersion('short prompt with extra words appended here');

        $delta = $this->action->execute($candidate, $baseline);

        $this->assertGreaterThan(0, $delta);
        $this->assertEquals(5, $delta); // "with extra words appended here" = 5 extra tokens
    }

    public function test_negative_delta_when_candidate_is_shorter(): void
    {
        $baseline = $this->makeVersion('a very long and verbose prompt template here');
        $candidate = $this->makeVersion('short prompt');

        $delta = $this->action->execute($candidate, $baseline);

        $this->assertLessThan(0, $delta);
    }

    public function test_handles_empty_template(): void
    {
        $baseline = $this->makeVersion('some prompt');
        $candidate = $this->makeVersion('');

        $delta = $this->action->execute($candidate, $baseline);

        $this->assertLessThan(0, $delta);
    }
}
