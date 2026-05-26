<?php

namespace Tests\Feature\Marketplace;

use App\Domain\Agent\Enums\AgentReasoningStrategy;
use App\Domain\Agent\Models\Agent;
use App\Domain\Marketplace\Actions\InstallFromMarketplaceAction;
use App\Domain\Marketplace\Actions\PublishToMarketplaceAction;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentMarketplaceRoundTripTest extends TestCase
{
    use RefreshDatabase;

    public function test_published_agent_round_trips_full_definition_through_marketplace(): void
    {
        $user = User::factory()->create();
        $source = Team::factory()->create();

        $agent = Agent::factory()->create([
            'team_id' => $source->id,
            'role' => 'Analyst',
            'goal' => 'Analyze pricing',
            'backstory' => 'Veteran pricing analyst',
            'personality' => ['tone' => 'formal'],
            'system_prompt_template' => ['rules' => ['Be precise']],
            'reasoning_strategy' => AgentReasoningStrategy::PlanAndExecute,
            'output_schema' => ['type' => 'object', 'properties' => ['verdict' => ['type' => 'string']]],
            'capabilities' => ['web_search'],
            'constraints' => ['No speculation'],
        ]);

        $listing = app(PublishToMarketplaceAction::class)->execute(
            item: $agent,
            teamId: $source->id,
            userId: $user->id,
            name: 'Pricing Analyst',
            description: 'A pricing analysis agent',
        );

        $target = Team::factory()->create();
        $installation = app(InstallFromMarketplaceAction::class)->execute($listing, $target->id, $user->id);

        $installed = Agent::findOrFail($installation->installed_agent_id);

        // Fields that were previously dropped by the publish/install asymmetry.
        $this->assertSame('Veteran pricing analyst', $installed->backstory);
        $this->assertSame(['tone' => 'formal'], $installed->personality);
        $this->assertSame(['rules' => ['Be precise']], $installed->system_prompt_template);
        $this->assertSame(AgentReasoningStrategy::PlanAndExecute, $installed->reasoning_strategy);
        $this->assertSame('object', $installed->output_schema['type']);
        // Fields that already round-tripped.
        $this->assertSame('Analyst', $installed->role);
        $this->assertSame(['web_search'], $installed->capabilities);
        $this->assertSame(['No speculation'], $installed->constraints);
    }

    public function test_legacy_snapshot_without_reasoning_strategy_falls_back_to_default(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();

        $agent = Agent::factory()->create(['team_id' => $team->id, 'role' => 'Helper']);
        $listing = app(PublishToMarketplaceAction::class)->execute(
            item: $agent, teamId: $team->id, userId: $user->id,
            name: 'Helper', description: 'desc',
        );

        // Simulate a listing published before reasoning_strategy was captured.
        $snapshot = $listing->configuration_snapshot;
        unset($snapshot['reasoning_strategy']);
        $listing->update(['configuration_snapshot' => $snapshot]);

        $installation = app(InstallFromMarketplaceAction::class)->execute($listing, $team->id, $user->id);
        $installed = Agent::findOrFail($installation->installed_agent_id);

        // NOT NULL column must not be violated; falls back to the column default.
        $this->assertSame(AgentReasoningStrategy::FunctionCalling, $installed->reasoning_strategy);
    }
}
