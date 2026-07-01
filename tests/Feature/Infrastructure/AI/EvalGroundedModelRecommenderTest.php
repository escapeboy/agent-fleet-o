<?php

namespace Tests\Feature\Infrastructure\AI;

use App\Domain\Agent\Models\AiRun;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Services\EvalGroundedModelRecommender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EvalGroundedModelRecommenderTest extends TestCase
{
    use RefreshDatabase;

    private EvalGroundedModelRecommender $recommender;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recommender = app(EvalGroundedModelRecommender::class);
        $this->team = Team::factory()->create();

        config()->set('ai_routing.eval_grounded.enabled', true);
        config()->set('ai_routing.eval_grounded.min_samples', 3);
        config()->set('ai_routing.eval_grounded.success_threshold', 0.9);
        config()->set('ai_routing.eval_grounded.platform_fallback', false);
    }

    private function makeRuns(string $model, int $count, int $credits, ?bool $verificationPassed, string $status = 'completed', string $purpose = 'scoring'): void
    {
        for ($i = 0; $i < $count; $i++) {
            AiRun::create([
                'team_id' => $this->team->id,
                'purpose' => $purpose,
                'provider' => 'anthropic',
                'model' => $model,
                'prompt_snapshot' => [],
                'cost_credits' => $credits,
                'status' => $status,
                'verification_passed' => $verificationPassed,
            ]);
        }
    }

    private function request(string $model = 'claude-opus-4-6', ?string $teamId = null, string $purpose = 'scoring'): AiRequestDTO
    {
        return new AiRequestDTO(
            provider: 'anthropic',
            model: $model,
            systemPrompt: 'x',
            userPrompt: 'y',
            teamId: $teamId ?? $this->team->id,
            purpose: $purpose,
        );
    }

    public function test_returns_null_when_flag_disabled(): void
    {
        config()->set('ai_routing.eval_grounded.enabled', false);
        $this->makeRuns('claude-haiku-4-5', 5, 5, true);

        $this->assertNull($this->recommender->recommend($this->request()));
    }

    public function test_returns_null_without_team(): void
    {
        $this->assertNull($this->recommender->recommend($this->request(teamId: '')));
    }

    public function test_recommends_cheapest_passing_model_and_flags_downgrade(): void
    {
        // Cheap model clears the bar; expensive model also passes but costs more.
        $this->makeRuns('claude-haiku-4-5', 5, 5, true);
        $this->makeRuns('claude-opus-4-6', 5, 50, true);

        // Chosen model on the request is the expensive one.
        $rec = $this->recommender->recommend($this->request(model: 'claude-opus-4-6'));

        $this->assertNotNull($rec);
        $this->assertSame('claude-haiku-4-5', $rec['recommended_model']);
        $this->assertTrue($rec['would_downgrade']);
        $this->assertSame(45.0, $rec['est_savings_per_call']);
        $this->assertSame('team', $rec['scope']);
    }

    public function test_skips_cheap_model_that_fails_quality_bar(): void
    {
        // Cheap model fails verification on every run → not a candidate.
        $this->makeRuns('claude-haiku-4-5', 5, 5, false);
        $this->makeRuns('claude-opus-4-6', 5, 50, true);

        $rec = $this->recommender->recommend($this->request(model: 'claude-opus-4-6'));

        $this->assertNotNull($rec);
        $this->assertSame('claude-opus-4-6', $rec['recommended_model']);
        $this->assertFalse($rec['would_downgrade']);
    }

    public function test_returns_null_when_samples_below_minimum(): void
    {
        $this->makeRuns('claude-haiku-4-5', 2, 5, true);

        $this->assertNull($this->recommender->recommend($this->request()));
    }

    public function test_falls_back_to_platform_wide_when_team_sparse(): void
    {
        config()->set('ai_routing.eval_grounded.platform_fallback', true);

        $otherTeam = Team::factory()->create();
        for ($i = 0; $i < 5; $i++) {
            AiRun::create([
                'team_id' => $otherTeam->id,
                'purpose' => 'scoring',
                'provider' => 'anthropic',
                'model' => 'claude-haiku-4-5',
                'prompt_snapshot' => [],
                'cost_credits' => 5,
                'status' => 'completed',
                'verification_passed' => true,
            ]);
        }

        $rec = $this->recommender->recommend($this->request());

        $this->assertNotNull($rec);
        $this->assertSame('platform', $rec['scope']);
        $this->assertSame('claude-haiku-4-5', $rec['recommended_model']);
    }
}
