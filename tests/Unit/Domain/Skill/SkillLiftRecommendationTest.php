<?php

namespace Tests\Unit\Domain\Skill;

use App\Domain\Skill\Enums\SkillLiftRecommendation;
use Tests\TestCase;

class SkillLiftRecommendationTest extends TestCase
{
    public function test_delta_maps_to_recommendation_tiers(): void
    {
        $this->assertSame(SkillLiftRecommendation::HighlyRecommended, SkillLiftRecommendation::fromDelta(2.0));
        $this->assertSame(SkillLiftRecommendation::HighlyRecommended, SkillLiftRecommendation::fromDelta(1.5));
        $this->assertSame(SkillLiftRecommendation::Recommended, SkillLiftRecommendation::fromDelta(0.5));
        $this->assertSame(SkillLiftRecommendation::Recommended, SkillLiftRecommendation::fromDelta(1.49));
        $this->assertSame(SkillLiftRecommendation::Conditional, SkillLiftRecommendation::fromDelta(0.1));
        $this->assertSame(SkillLiftRecommendation::Conditional, SkillLiftRecommendation::fromDelta(0.49));
        $this->assertSame(SkillLiftRecommendation::Marginal, SkillLiftRecommendation::fromDelta(0.0));
        $this->assertSame(SkillLiftRecommendation::Marginal, SkillLiftRecommendation::fromDelta(-0.1));
        $this->assertSame(SkillLiftRecommendation::Harmful, SkillLiftRecommendation::fromDelta(-0.5));
        $this->assertSame(SkillLiftRecommendation::Harmful, SkillLiftRecommendation::fromDelta(-5.0));
    }
}
