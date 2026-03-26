<?php

namespace Tests\Feature\Domain\Skill;

use App\Domain\Shared\Models\Team;
use App\Domain\Skill\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SkillQualityMetricsTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team',
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($user, ['role' => 'owner']);
    }

    private function makeSkill(array $overrides = []): Skill
    {
        return Skill::create(array_merge([
            'team_id' => $this->team->id,
            'name' => 'Test Skill',
            'slug' => 'test-skill-'.uniqid(),
            'type' => 'llm',
            'status' => 'active',
            'configuration' => [],
            'applied_count' => 0,
            'completed_count' => 0,
            'effective_count' => 0,
            'fallback_count' => 0,
        ], $overrides));
    }

    public function test_reliability_rate_returns_zero_when_applied_count_is_zero(): void
    {
        $skill = $this->makeSkill(['applied_count' => 0, 'completed_count' => 0]);

        $this->assertEquals(0.0, $skill->reliability_rate);
    }

    public function test_reliability_rate_returns_correct_value(): void
    {
        $skill = $this->makeSkill(['applied_count' => 10, 'completed_count' => 8]);

        $this->assertEquals(0.8, $skill->reliability_rate);
    }

    public function test_quality_rate_returns_zero_when_completed_count_is_zero(): void
    {
        $skill = $this->makeSkill(['completed_count' => 0, 'effective_count' => 0]);

        $this->assertEquals(0.0, $skill->quality_rate);
    }

    public function test_quality_rate_returns_correct_value(): void
    {
        $skill = $this->makeSkill(['completed_count' => 8, 'effective_count' => 6]);

        $this->assertEquals(0.75, $skill->quality_rate);
    }

    public function test_fallback_rate_returns_correct_value(): void
    {
        $skill = $this->makeSkill(['applied_count' => 10, 'fallback_count' => 3]);

        $this->assertEquals(0.3, $skill->fallback_rate);
    }

    public function test_health_score_returns_weighted_composite_score(): void
    {
        // reliability=0.8, quality=0.75, fallback_rate=0.2 → (1-fallback)=0.8
        // health = 0.8*0.4 + 0.75*0.4 + 0.8*0.2 = 0.32 + 0.30 + 0.16 = 0.78
        $skill = $this->makeSkill([
            'applied_count' => 10,
            'completed_count' => 8,
            'effective_count' => 6,
            'fallback_count' => 2,
        ]);

        $expected = round((0.8 * 0.4) + (0.75 * 0.4) + ((1 - 0.2) * 0.2), 4);
        $this->assertEquals($expected, $skill->health_score);
    }

    public function test_is_degraded_returns_false_when_sample_too_small(): void
    {
        // applied_count < min_sample_size (default 10) → never degraded
        $skill = $this->makeSkill([
            'applied_count' => 5,
            'completed_count' => 1, // would be low reliability if sample was large enough
            'effective_count' => 0,
        ]);

        $this->assertFalse($skill->isDegraded());
    }

    public function test_is_degraded_returns_true_when_reliability_below_threshold(): void
    {
        // reliability = 4/10 = 0.4, below threshold 0.6
        $skill = $this->makeSkill([
            'applied_count' => 10,
            'completed_count' => 4,
            'effective_count' => 4,
        ]);

        $this->assertTrue($skill->isDegraded());
    }

    public function test_is_degraded_returns_true_when_quality_below_threshold(): void
    {
        // reliability = 10/10 = 1.0 (above threshold)
        // quality = 4/10 = 0.4, below threshold 0.5
        $skill = $this->makeSkill([
            'applied_count' => 10,
            'completed_count' => 10,
            'effective_count' => 4,
        ]);

        $this->assertTrue($skill->isDegraded());
    }

    public function test_is_degraded_returns_false_when_both_rates_above_threshold(): void
    {
        // reliability = 8/10 = 0.8 (above 0.6)
        // quality = 6/8 = 0.75 (above 0.5)
        $skill = $this->makeSkill([
            'applied_count' => 10,
            'completed_count' => 8,
            'effective_count' => 6,
        ]);

        $this->assertFalse($skill->isDegraded());
    }
}
