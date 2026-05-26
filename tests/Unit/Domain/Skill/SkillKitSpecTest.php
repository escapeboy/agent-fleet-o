<?php

namespace Tests\Unit\Domain\Skill;

use App\Domain\Skill\Enums\RiskLevel;
use App\Domain\Skill\Enums\SkillType;
use App\Domain\Skill\Support\SkillKitSpec;
use Tests\TestCase;

class SkillKitSpecTest extends TestCase
{
    public function test_missing_sections_detects_both_when_absent(): void
    {
        $this->assertSame(
            ['When to Use', 'Boundaries'],
            SkillKitSpec::missingSections("# Title\n\nJust instructions."),
        );
    }

    public function test_missing_sections_empty_when_present_case_insensitive(): void
    {
        $body = "## when TO use\n\nx\n\n## BOUNDARIES\n\ny";

        $this->assertSame([], SkillKitSpec::missingSections($body));
    }

    public function test_missing_sections_reports_only_the_gap(): void
    {
        $body = "## When to Use\n\nWhen scoring leads.";

        $this->assertSame(['Boundaries'], SkillKitSpec::missingSections($body));
    }

    public function test_append_fills_both_sections(): void
    {
        $result = SkillKitSpec::appendRecommendedSections(
            'Base instructions.',
            'Scores inbound leads.',
            RiskLevel::Low,
            SkillType::Llm,
        );

        $this->assertStringContainsString('## When to Use', $result);
        $this->assertStringContainsString('Scores inbound leads.', $result);
        $this->assertStringContainsString('## Boundaries', $result);
        $this->assertSame([], SkillKitSpec::missingSections($result));
    }

    public function test_append_is_idempotent(): void
    {
        $once = SkillKitSpec::appendRecommendedSections('Body.', 'desc', RiskLevel::Low, SkillType::Llm);
        $twice = SkillKitSpec::appendRecommendedSections($once, 'desc', RiskLevel::Low, SkillType::Llm);

        $this->assertSame($once, $twice);
        $this->assertSame(1, substr_count($twice, '## When to Use'));
        $this->assertSame(1, substr_count($twice, '## Boundaries'));
    }

    public function test_high_risk_boundaries_mention_approval(): void
    {
        $high = SkillKitSpec::appendRecommendedSections('B.', 'd', RiskLevel::High, SkillType::Guardrail);
        $low = SkillKitSpec::appendRecommendedSections('B.', 'd', RiskLevel::Low, SkillType::Guardrail);

        $this->assertStringContainsString('requires human approval', $high);
        $this->assertStringNotContainsString('requires human approval', $low);
    }
}
