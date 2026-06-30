<?php

namespace Tests\Feature\Livewire\Metrics;

use App\Domain\Agent\Models\AiRun;
use App\Domain\Shared\Models\Team;
use App\Livewire\Metrics\AiRoutingPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AiRoutingSavingsTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $this->team = Team::factory()->create(['owner_id' => $user->id]);
        $user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($user, ['role' => 'owner']);
        $this->actingAs($user);

        config()->set('ai_routing.savings_baseline', ['provider' => 'anthropic', 'model' => 'claude-opus-4-6']);
    }

    public function test_reports_credits_saved_vs_flagship_in_credits(): void
    {
        // Cheap model, huge token volume, tiny actual credit spend → re-pricing
        // at the opus baseline must dwarf the actual cost.
        for ($i = 0; $i < 5; $i++) {
            AiRun::create([
                'team_id' => $this->team->id,
                'purpose' => 'scoring',
                'provider' => 'anthropic',
                'model' => 'claude-haiku-4-5',
                'prompt_snapshot' => [],
                'input_tokens' => 200_000,
                'output_tokens' => 200_000,
                'cost_credits' => 10,
                'status' => 'completed',
            ]);
        }

        Livewire::test(AiRoutingPage::class)
            ->assertViewHas('costSavings', function ($cs) {
                return $cs->actual === 50
                    && $cs->theoretical > $cs->actual
                    && $cs->saved_credits === ($cs->theoretical - $cs->actual)
                    && $cs->savings_pct > 0
                    && $cs->baseline_model === 'claude-opus-4-6';
            });
    }

    public function test_zero_savings_with_no_runs(): void
    {
        Livewire::test(AiRoutingPage::class)
            ->assertViewHas('costSavings', function ($cs) {
                return $cs->actual === 0
                    && $cs->theoretical === 0
                    && $cs->saved_credits === 0
                    && $cs->savings_pct === 0;
            });
    }
}
