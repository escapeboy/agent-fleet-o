<?php

namespace Tests\Feature\Domain\Assistant;

use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use App\Domain\Assistant\Services\RequestRouter;
use App\Domain\Project\Enums\ProjectStatus;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequestRouterTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $this->team = Team::factory()->create();
    }

    private function router(): RequestRouter
    {
        return app(RequestRouter::class);
    }

    public function test_returns_empty_for_blank_request(): void
    {
        Agent::factory()->create(['team_id' => $this->team->id, 'status' => AgentStatus::Active]);
        $this->assertSame([], $this->router()->route($this->team->id, '   '));
    }

    public function test_ranks_best_fit_agent_first(): void
    {
        Agent::factory()->create([
            'team_id' => $this->team->id,
            'status' => AgentStatus::Active,
            'name' => 'DataBot',
            'role' => 'data analyst',
            'goal' => 'answer revenue and sales questions',
        ]);
        Agent::factory()->create([
            'team_id' => $this->team->id,
            'status' => AgentStatus::Active,
            'name' => 'DevBot',
            'role' => 'software engineer',
            'goal' => 'fix bugs and write code',
        ]);

        $ranked = $this->router()->route($this->team->id, 'What was our revenue last week?');

        $this->assertNotEmpty($ranked);
        $this->assertSame('DataBot', $ranked[0]['name']);
        $this->assertContains('revenue', $ranked[0]['why']);
    }

    public function test_includes_projects_as_candidates(): void
    {
        Project::factory()->create([
            'team_id' => $this->team->id,
            'status' => ProjectStatus::Active,
            'title' => 'Weekly revenue report',
            'description' => 'Summarize revenue every Monday',
        ]);

        $ranked = $this->router()->route($this->team->id, 'generate the revenue report');

        $this->assertNotEmpty($ranked);
        $this->assertSame('project', $ranked[0]['kind']);
        $this->assertSame('Weekly revenue report', $ranked[0]['name']);
    }

    public function test_no_match_returns_empty(): void
    {
        Agent::factory()->create([
            'team_id' => $this->team->id,
            'status' => AgentStatus::Active,
            'name' => 'DataBot',
            'role' => 'data analyst',
            'goal' => 'answer revenue questions',
        ]);

        $this->assertSame([], $this->router()->route($this->team->id, 'photosynthesis chlorophyll'));
    }

    public function test_inactive_agents_are_excluded(): void
    {
        Agent::factory()->create([
            'team_id' => $this->team->id,
            'status' => AgentStatus::Disabled,
            'name' => 'OldBot',
            'goal' => 'handle revenue analysis',
        ]);

        $this->assertSame([], $this->router()->route($this->team->id, 'revenue analysis'));
    }

    public function test_is_tenant_isolated(): void
    {
        $teamB = Team::factory()->create();
        Agent::factory()->create([
            'team_id' => $teamB->id,
            'status' => AgentStatus::Active,
            'name' => 'OtherTeamBot',
            'goal' => 'handle revenue analysis',
        ]);

        $this->assertSame([], $this->router()->route($this->team->id, 'revenue analysis'));
    }
}
