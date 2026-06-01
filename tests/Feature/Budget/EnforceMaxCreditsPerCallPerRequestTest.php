<?php

namespace Tests\Feature\Budget;

use App\Domain\Budget\Actions\EnforceMaxCreditsPerCallAction;
use App\Domain\Budget\Exceptions\InsufficientBudgetException;
use App\Domain\Budget\Services\CostCalculator;
use App\Domain\Shared\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnforceMaxCreditsPerCallPerRequestTest extends TestCase
{
    use RefreshDatabase;

    private EnforceMaxCreditsPerCallAction $action;

    private const PROVIDER = 'anthropic';

    private const MODEL = 'claude-sonnet-4-5';

    private const MAX_OUTPUT = 8192;

    protected function setUp(): void
    {
        parent::setUp();
        // No standing per-call cap unless a test sets one.
        config(['llm_pricing.max_credits_per_call' => null]);
        $this->action = app(EnforceMaxCreditsPerCallAction::class);
    }

    public function test_per_request_cap_below_estimate_throws_without_standing_cap(): void
    {
        $team = Team::factory()->create();
        $estimate = $this->estimateFor($team);
        $this->assertGreaterThan(0, $estimate, 'pricing must yield a positive estimate');

        $this->expectException(InsufficientBudgetException::class);

        $this->enforce($team, perRequestCap: $estimate - 1);
    }

    public function test_per_request_cap_above_estimate_passes(): void
    {
        $team = Team::factory()->create();
        $estimate = $this->estimateFor($team);

        $this->enforce($team, perRequestCap: $estimate + 100);

        $this->expectNotToPerformAssertions();
    }

    public function test_null_per_request_cap_with_no_standing_cap_passes(): void
    {
        $team = Team::factory()->create();

        $this->enforce($team, perRequestCap: null);

        $this->expectNotToPerformAssertions();
    }

    public function test_lower_of_standing_and_per_request_cap_wins(): void
    {
        $team = Team::factory()->create();
        $estimate = $this->estimateFor($team);

        // Standing cap generous, per-request cap tight → per-request (lower) must bite.
        config(['llm_pricing.max_credits_per_call' => $estimate + 1000]);

        $this->expectException(InsufficientBudgetException::class);

        $this->enforce($team, perRequestCap: $estimate - 1);
    }

    public function test_standing_cap_still_enforced_when_lower_than_per_request(): void
    {
        $team = Team::factory()->create();
        $estimate = $this->estimateFor($team);

        config(['llm_pricing.max_credits_per_call' => max(0, $estimate - 1)]);

        $this->expectException(InsufficientBudgetException::class);

        $this->enforce($team, perRequestCap: $estimate + 1000);
    }

    private function enforce(Team $team, ?int $perRequestCap): void
    {
        $this->action->execute(
            teamId: $team->id,
            provider: self::PROVIDER,
            model: self::MODEL,
            maxOutputTokens: self::MAX_OUTPUT,
            perRequestCap: $perRequestCap,
        );
    }

    private function estimateFor(Team $team): int
    {
        return app(CostCalculator::class)->estimatePlatformCredits(
            provider: self::PROVIDER,
            model: self::MODEL,
            estimatedInputTokens: 500,
            maxOutputTokens: self::MAX_OUTPUT,
            cacheStrategy: null,
            marginOverride: $team->effectiveMarginMultiplier(),
        );
    }
}
