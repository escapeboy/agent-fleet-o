<?php

namespace Tests\Feature\Domain\Tool;

use App\Domain\Agent\Models\Agent;
use App\Domain\Tool\Actions\ResolveAgentToolsAction;
use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Models\Tool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SemanticPreFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolve_passes_semantic_query_when_above_threshold(): void
    {
        // Set threshold very low so we can trigger filtering
        config(['tools.semantic_filter_threshold' => 1]);

        $agent = Agent::factory()->create();

        // Create enough tools to exceed threshold
        $tools = Tool::factory()->count(3)->create([
            'team_id' => $agent->team_id,
            'type' => ToolType::BuiltIn,
            'status' => ToolStatus::Active,
            'transport_config' => ['kind' => 'bash'],
        ]);

        foreach ($tools as $tool) {
            $agent->tools()->attach($tool->id);
        }

        // The action should work without errors even when pgvector is unavailable (SQLite)
        // It should fallback to returning all tools
        $action = app(ResolveAgentToolsAction::class);
        $result = $action->execute(
            agent: $agent,
            semanticQuery: 'search for files on disk',
        );

        // On SQLite, semantic filter returns empty → fallback returns all tools
        $this->assertNotEmpty($result);
    }

    public function test_resolve_skips_filtering_when_below_threshold(): void
    {
        config(['tools.semantic_filter_threshold' => 100]); // Very high threshold

        $agent = Agent::factory()->create();
        $tool = Tool::factory()->create([
            'team_id' => $agent->team_id,
            'type' => ToolType::BuiltIn,
            'status' => ToolStatus::Active,
            'transport_config' => ['kind' => 'bash'],
        ]);
        $agent->tools()->attach($tool->id);

        $action = app(ResolveAgentToolsAction::class);
        $result = $action->execute(
            agent: $agent,
            semanticQuery: 'anything',
        );

        // Below threshold — all tools returned without filtering
        $this->assertNotEmpty($result);
    }

    public function test_resolve_works_without_semantic_query(): void
    {
        $agent = Agent::factory()->create();
        $tool = Tool::factory()->create([
            'team_id' => $agent->team_id,
            'type' => ToolType::BuiltIn,
            'status' => ToolStatus::Active,
            'transport_config' => ['kind' => 'bash'],
        ]);
        $agent->tools()->attach($tool->id);

        $action = app(ResolveAgentToolsAction::class);
        $result = $action->execute(agent: $agent);

        $this->assertNotEmpty($result);
    }
}
