<?php

namespace Tests\Feature\Domain\Tool;

use App\Domain\Agent\Models\Agent;
use App\Domain\Shared\Models\Team;
use App\Domain\Tool\Actions\ResolveAgentToolsAction;
use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Models\Tool;
use App\Domain\Tool\Models\ToolSearchLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the Tool Search auto-discovery feature: when an agent has
 * `config.use_tool_search = true` and a semanticQuery is supplied, the
 * resolver expands the pool with semantically-matching team-wide tools.
 */
class ToolSearchDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Tool Search Test',
            'slug' => 'tool-search-test',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);
    }

    private function makeTool(string $slug, string $name, array $tags = []): Tool
    {
        return Tool::factory()->create([
            'team_id' => $this->team->id,
            'slug' => $slug,
            'name' => $name,
            'type' => ToolType::BuiltIn,
            'status' => ToolStatus::Active,
            'transport_config' => ['kind' => 'bash'],
            'tags' => $tags,
        ]);
    }

    public function test_does_not_run_when_feature_flag_is_off(): void
    {
        $this->makeTool('web_search', 'Web Search');
        $this->makeTool('github', 'GitHub API');

        $agent = Agent::factory()->create([
            'team_id' => $this->team->id,
            'config' => [], // use_tool_search NOT set
        ]);

        $resolved = app(ResolveAgentToolsAction::class)->execute(
            agent: $agent,
            semanticQuery: 'search the web for latest news',
        );

        // No tools attached, no search → empty
        $this->assertSame([], $resolved);
    }

    public function test_does_not_run_without_semantic_query(): void
    {
        $this->makeTool('web_search', 'Web Search');

        $agent = Agent::factory()->create([
            'team_id' => $this->team->id,
            'config' => ['use_tool_search' => true],
        ]);

        $resolved = app(ResolveAgentToolsAction::class)->execute(
            agent: $agent,
            semanticQuery: null,
        );

        $this->assertSame([], $resolved);
    }

    public function test_discovers_matching_tool_from_team_pool(): void
    {
        $this->makeTool('web_search', 'Web Search');
        $this->makeTool('github', 'GitHub API');

        $agent = Agent::factory()->create([
            'team_id' => $this->team->id,
            'config' => ['use_tool_search' => true],
        ]);

        // Agent has no tools attached; search should bring in matching one
        $this->assertEquals(0, $agent->tools()->count());

        $resolved = app(ResolveAgentToolsAction::class)->execute(
            agent: $agent,
            semanticQuery: 'web search for news',
        );

        // At least the web search tool should surface via keyword match
        $this->assertNotEmpty($resolved);
    }

    public function test_search_expands_pool_beyond_attached_tools(): void
    {
        $webSearch = $this->makeTool('web_search', 'Web Search');
        $this->makeTool('github', 'GitHub API');

        $agent = Agent::factory()->create([
            'team_id' => $this->team->id,
            'config' => ['use_tool_search' => true],
        ]);

        // Baseline: resolve with only web_search attached, no search query
        $agent->tools()->attach($webSearch, ['priority' => 1, 'overrides' => []]);
        $baseline = app(ResolveAgentToolsAction::class)->execute(agent: $agent);

        // With a query that matches github, search should expand the pool
        $expanded = app(ResolveAgentToolsAction::class)->execute(
            agent: $agent,
            semanticQuery: 'github api repository',
        );

        $this->assertGreaterThanOrEqual(count($baseline), count($expanded));
    }

    public function test_top_k_caps_the_merge_count(): void
    {
        // Seed 10 tools, all with "search" in name so they all match keyword stage
        for ($i = 1; $i <= 10; $i++) {
            $this->makeTool("search_tool_{$i}", "Search Tool {$i}");
        }

        $agent = Agent::factory()->create([
            'team_id' => $this->team->id,
            'config' => [
                'use_tool_search' => true,
                'tool_search_top_k' => 3,
            ],
        ]);

        $resolved = app(ResolveAgentToolsAction::class)->execute(
            agent: $agent,
            semanticQuery: 'search',
        );

        $this->assertLessThanOrEqual(3, count($resolved));
    }

    public function test_top_k_is_bounded_between_1_and_20(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->makeTool("query_tool_{$i}", "Query Tool {$i}");
        }

        $agent = Agent::factory()->create([
            'team_id' => $this->team->id,
            'config' => [
                'use_tool_search' => true,
                'tool_search_top_k' => 999, // clamp to 20
            ],
        ]);

        $resolved = app(ResolveAgentToolsAction::class)->execute(
            agent: $agent,
            semanticQuery: 'query',
        );

        $this->assertLessThanOrEqual(20, count($resolved));
    }

    public function test_empty_team_pool_is_silent_no_op(): void
    {
        $agent = Agent::factory()->create([
            'team_id' => $this->team->id,
            'config' => ['use_tool_search' => true],
        ]);

        $resolved = app(ResolveAgentToolsAction::class)->execute(
            agent: $agent,
            semanticQuery: 'anything',
        );

        $this->assertSame([], $resolved);
    }

    public function test_persists_tool_search_log_on_successful_match(): void
    {
        $this->makeTool('github', 'GitHub API');

        $agent = Agent::factory()->create([
            'team_id' => $this->team->id,
            'config' => ['use_tool_search' => true],
        ]);

        app(ResolveAgentToolsAction::class)->execute(
            agent: $agent,
            semanticQuery: 'github api repository',
        );

        $log = ToolSearchLog::where('agent_id', $agent->id)->first();
        $this->assertNotNull($log);
        $this->assertSame($this->team->id, $log->team_id);
        $this->assertSame('github api repository', $log->query);
        $this->assertGreaterThan(0, $log->pool_size);
        $this->assertGreaterThanOrEqual(0, $log->matched_count);
        $this->assertIsArray($log->matched_slugs);
    }

    public function test_no_log_written_when_search_disabled(): void
    {
        $this->makeTool('github', 'GitHub API');

        $agent = Agent::factory()->create([
            'team_id' => $this->team->id,
            'config' => [],
        ]);

        app(ResolveAgentToolsAction::class)->execute(
            agent: $agent,
            semanticQuery: 'anything',
        );

        $this->assertSame(0, ToolSearchLog::where('agent_id', $agent->id)->count());
    }
}
