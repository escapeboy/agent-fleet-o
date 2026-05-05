<?php

namespace Tests\Feature\Livewire;

use App\Domain\Agent\Models\Agent;
use App\Domain\Shared\Models\Team;
use App\Domain\Tool\Models\ToolSearchLog;
use App\Livewire\Tools\ToolSearchHistoryPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ToolSearchHistoryPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Search History Test',
            'slug' => 'search-history-test',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);
    }

    private function makeLog(array $overrides = []): ToolSearchLog
    {
        return ToolSearchLog::create(array_merge([
            'team_id' => $this->team->id,
            'query' => 'test query',
            'pool_size' => 10,
            'matched_count' => 2,
            'matched_slugs' => ['web_search', 'github'],
            'matched_ids' => [],
            'created_at' => now(),
        ], $overrides));
    }

    public function test_lists_logs_for_current_team(): void
    {
        $this->makeLog(['query' => 'fetch latest issues']);
        $this->makeLog(['query' => 'deploy to staging']);

        Livewire::test(ToolSearchHistoryPage::class)
            ->assertSee('fetch latest issues')
            ->assertSee('deploy to staging');
    }

    public function test_filters_logs_by_search_query(): void
    {
        $this->makeLog(['query' => 'github api']);
        $this->makeLog(['query' => 'slack webhook']);

        Livewire::test(ToolSearchHistoryPage::class)
            ->set('search', 'github')
            ->assertSee('github api')
            ->assertDontSee('slack webhook');
    }

    public function test_filters_logs_by_agent(): void
    {
        $agent1 = Agent::factory()->create([
            'team_id' => $this->team->id,
            'config' => ['use_tool_search' => true],
        ]);
        $agent2 = Agent::factory()->create([
            'team_id' => $this->team->id,
            'config' => ['use_tool_search' => true],
        ]);

        $this->makeLog(['agent_id' => $agent1->id, 'query' => 'query-from-agent-1']);
        $this->makeLog(['agent_id' => $agent2->id, 'query' => 'query-from-agent-2']);

        Livewire::test(ToolSearchHistoryPage::class)
            ->set('agentFilter', $agent1->id)
            ->assertSee('query-from-agent-1')
            ->assertDontSee('query-from-agent-2');
    }

    public function test_empty_state_when_no_logs(): void
    {
        Livewire::test(ToolSearchHistoryPage::class)
            ->assertSee('No tool search events yet');
    }

    public function test_invalid_agent_filter_uuid_is_silently_reset(): void
    {
        $this->makeLog(['query' => 'should still appear']);

        Livewire::test(ToolSearchHistoryPage::class)
            ->set('agentFilter', 'not-a-uuid')
            ->assertSet('agentFilter', '')
            ->assertSee('should still appear');
    }

    public function test_aggregates_stats_across_logs(): void
    {
        $this->makeLog(['pool_size' => 20, 'matched_count' => 5, 'matched_slugs' => ['github', 'slack']]);
        $this->makeLog(['pool_size' => 30, 'matched_count' => 3, 'matched_slugs' => ['github']]);
        $this->makeLog(['pool_size' => 10, 'matched_count' => 0, 'matched_slugs' => []]);

        Livewire::test(ToolSearchHistoryPage::class)
            // total
            ->assertSee('3')
            // avg matched = (5+3+0)/3 = 2.67
            ->assertSee('2.67')
            // avg pool = (20+30+10)/3 = 20
            // zero-match rate = 1/3 ~ 33%
            ->assertSee('33%')
            // top surfaced slug (github appears 2x)
            ->assertSee('github');
    }
}
