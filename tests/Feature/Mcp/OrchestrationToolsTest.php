<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Models\Crew;
use App\Domain\Shared\Models\Team;
use App\Mcp\Tools\Orchestration\CrewCostEstimateTool;
use App\Mcp\Tools\Orchestration\OrchestrationRecommendTierTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Tests\TestCase;

class OrchestrationToolsTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Orch Team',
            'slug' => 'orch-team',
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $this->team->id]);
        $this->actingAs($user);
        app()->instance('mcp.team_id', $this->team->id);
    }

    public function test_crew_cost_estimate_tool(): void
    {
        $crew = Crew::factory()->create([
            'team_id' => $this->team->id,
            'max_task_iterations' => 3,
            'coordinator_agent_id' => Agent::factory()->create(['team_id' => $this->team->id])->id,
            'qa_agent_id' => Agent::factory()->create(['team_id' => $this->team->id])->id,
        ]);

        $data = $this->decode((new CrewCostEstimateTool)->handle(new Request(['crew_id' => $crew->id])));

        $this->assertSame(44, $data['projected_credits']);
        $this->assertArrayHasKey('requires_confirmation', $data);
    }

    public function test_recommend_tier_tool_blocked_when_disabled(): void
    {
        config(['orchestration.tier_selector.enabled' => false]);

        $data = $this->decode((new OrchestrationRecommendTierTool)->handle(new Request(['goal' => 'compare options'])));

        $this->assertArrayHasKey('error', $data);
    }

    public function test_recommend_tier_tool_returns_recommendation_when_enabled(): void
    {
        config(['orchestration.tier_selector.enabled' => true]);

        $data = $this->decode((new OrchestrationRecommendTierTool)->handle(
            new Request(['goal' => 'Compare the top options and perspectives']),
        ));

        $this->assertSame('crew', $data['tier']);
        $this->assertSame('fanout', $data['process_type']);
    }

    private function decode(Response $response): array
    {
        return json_decode((string) $response->content(), true);
    }
}
