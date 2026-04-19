<?php

namespace Tests\Feature\Domain\Agent;

use App\Domain\Agent\Enums\AgentEnvironment;
use App\Domain\Agent\Models\Agent;
use App\Domain\Shared\Models\Team;
use App\Domain\Tool\Actions\ResolveAgentToolsAction;
use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Models\Tool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentEnvironmentResolveToolsTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Env Test Team',
            'slug' => 'env-test-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);
    }

    public function test_coding_environment_auto_attaches_bash_and_filesystem_tools(): void
    {
        Tool::factory()->create([
            'team_id' => $this->team->id,
            'slug' => 'bash',
            'type' => ToolType::BuiltIn,
            'status' => ToolStatus::Active,
            'transport_config' => ['kind' => 'bash'],
        ]);

        Tool::factory()->create([
            'team_id' => $this->team->id,
            'slug' => 'filesystem',
            'type' => ToolType::BuiltIn,
            'status' => ToolStatus::Active,
            'transport_config' => ['kind' => 'filesystem'],
        ]);

        $agent = Agent::factory()->create([
            'team_id' => $this->team->id,
            'environment' => AgentEnvironment::Coding->value,
        ]);

        // Agent has no explicitly attached tools — environment auto-attaches them
        $this->assertEquals(0, $agent->tools()->count());

        $resolvedTools = app(ResolveAgentToolsAction::class)->execute($agent);

        // Both built-in tools from the environment should be present
        $this->assertGreaterThan(0, count($resolvedTools));
    }

    public function test_minimal_environment_attaches_no_tools(): void
    {
        Tool::factory()->create([
            'team_id' => $this->team->id,
            'slug' => 'bash',
            'type' => ToolType::BuiltIn,
            'status' => ToolStatus::Active,
            'transport_config' => ['kind' => 'bash'],
        ]);

        $agent = Agent::factory()->create([
            'team_id' => $this->team->id,
            'environment' => AgentEnvironment::Minimal->value,
        ]);

        $resolvedTools = app(ResolveAgentToolsAction::class)->execute($agent);

        $this->assertSame([], $resolvedTools);
    }

    public function test_missing_environment_tools_are_silent_no_op(): void
    {
        // No tools seeded with slug 'browser' or 'web_search' for this team
        $agent = Agent::factory()->create([
            'team_id' => $this->team->id,
            'environment' => AgentEnvironment::Browsing->value,
        ]);

        $resolvedTools = app(ResolveAgentToolsAction::class)->execute($agent);

        $this->assertSame([], $resolvedTools);
    }

    public function test_environment_tools_dedupe_with_explicitly_attached(): void
    {
        $bash = Tool::factory()->create([
            'team_id' => $this->team->id,
            'slug' => 'bash',
            'type' => ToolType::BuiltIn,
            'status' => ToolStatus::Active,
            'transport_config' => ['kind' => 'bash'],
        ]);

        $agent = Agent::factory()->create([
            'team_id' => $this->team->id,
            'environment' => AgentEnvironment::Coding->value,
        ]);

        // Explicitly attach bash too — must not be duplicated
        $agent->tools()->attach($bash, ['priority' => 1, 'overrides' => []]);

        $resolvedTools = app(ResolveAgentToolsAction::class)->execute($agent);

        // Only one bash tool should be present despite double source
        $this->assertCount(1, $resolvedTools);
    }

    public function test_agent_without_environment_behaves_as_before(): void
    {
        Tool::factory()->create([
            'team_id' => $this->team->id,
            'slug' => 'bash',
            'type' => ToolType::BuiltIn,
            'status' => ToolStatus::Active,
            'transport_config' => ['kind' => 'bash'],
        ]);

        $agent = Agent::factory()->create([
            'team_id' => $this->team->id,
            'environment' => null,
        ]);

        $resolvedTools = app(ResolveAgentToolsAction::class)->execute($agent);

        // No environment + no explicit tools = zero resolved
        $this->assertSame([], $resolvedTools);
    }
}
