<?php

namespace Tests\Unit\Domain\Agent;

use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Services\AgentPromptCompiler;
use App\Domain\Memory\Services\MemoryNudgeInjector;
use Tests\TestCase;

class AgentPromptCompilerNudgeTest extends TestCase
{
    private function compilerReturning(?string $nudge): AgentPromptCompiler
    {
        return new AgentPromptCompiler(new class($nudge) extends MemoryNudgeInjector
        {
            public function __construct(private readonly ?string $nudge) {}

            public function nudgeFor(Agent $agent): ?string
            {
                return $this->nudge;
            }
        });
    }

    public function test_no_section_when_nudge_absent(): void
    {
        $agent = Agent::factory()->make([
            'team_id' => 'team-x',
            'system_prompt_template' => ['personality' => 'Helpful.'],
        ]);

        $compiled = $this->compilerReturning(null)->compile($agent);

        $this->assertStringNotContainsString('Persisting Knowledge', $compiled);
    }

    public function test_section_appended_when_nudge_present(): void
    {
        $agent = Agent::factory()->make([
            'team_id' => 'team-x',
            'system_prompt_template' => ['personality' => 'Helpful.'],
        ]);

        $compiled = $this->compilerReturning('SAVE YOUR LEARNINGS')->compile($agent);

        $this->assertStringContainsString('## Persisting Knowledge', $compiled);
        $this->assertStringContainsString('SAVE YOUR LEARNINGS', $compiled);
    }

    public function test_section_appended_to_backstory_when_template_empty(): void
    {
        $agent = Agent::factory()->make([
            'team_id' => 'team-x',
            'system_prompt_template' => [],
            'backstory' => 'Base story.',
        ]);

        $compiled = $this->compilerReturning('SAVE YOUR LEARNINGS')->compile($agent);

        $this->assertStringContainsString('Base story.', $compiled);
        $this->assertStringContainsString('## Persisting Knowledge', $compiled);
    }
}
