<?php

namespace Tests\Unit\Mcp;

use App\Mcp\Servers\AgentFleetServer;
use App\Mcp\Tools\Skill\SkillLiftEvalTool;
use App\Mcp\Tools\Skill\SkillLintTool;
use ReflectionClass;
use Tests\TestCase;

class SkillQualityToolsRegisteredTest extends TestCase
{
    public function test_lift_and_lint_tools_are_registered(): void
    {
        $tools = (new ReflectionClass(AgentFleetServer::class))
            ->getProperty('tools')
            ->getDefaultValue();

        $this->assertContains(SkillLiftEvalTool::class, $tools);
        $this->assertContains(SkillLintTool::class, $tools);
    }
}
