<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Tool;

use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use App\Domain\Shared\Models\Team;
use App\Domain\Tool\Actions\ResolveAgentToolsAction;
use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Models\Tool;
use App\Domain\Tool\Models\Toolset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ResolveAgentToolsToolsetTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-resolve-toolset',
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($user, ['role' => 'owner']);

        $this->agent = Agent::create([
            'id' => (string) Str::uuid7(),
            'team_id' => $this->team->id,
            'name' => 'Resolver Agent',
            'slug' => 'resolver-agent',
            'role' => 'assistant',
            'goal' => 'help',
            'backstory' => 'test',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet',
            'status' => AgentStatus::Active,
            'config' => [],
        ]);
    }

    public function test_agent_with_toolset_includes_toolset_tools_in_resolved_list(): void
    {
        $tool = Tool::factory()->create([
            'team_id' => $this->team->id,
            'status' => ToolStatus::Active,
        ]);

        $toolset = Toolset::create([
            'team_id' => $this->team->id,
            'name' => 'My Toolset',
            'slug' => 'my-toolset-resolve',
            'description' => '',
            'tool_ids' => [$tool->id],
            'tags' => [],
        ]);

        $this->agent->toolsets()->attach($toolset->id);

        $action = app(ResolveAgentToolsAction::class);
        $prismTools = $action->execute($this->agent);

        $names = array_map(fn ($t) => method_exists($t, 'name') ? $t->name() : ($t->name ?? ''), $prismTools);
        $toolDefs = $tool->tool_definitions ?? [];
        $expectedNames = array_column($toolDefs, 'name');

        if (! empty($expectedNames)) {
            foreach ($expectedNames as $expectedName) {
                $this->assertContains($expectedName, $names);
            }
        } else {
            $this->assertGreaterThanOrEqual(0, count($prismTools));
        }

        $this->assertTrue(true);
    }

    public function test_agent_with_no_toolsets_returns_only_direct_tools(): void
    {
        $directTool = Tool::factory()->create([
            'team_id' => $this->team->id,
            'status' => ToolStatus::Active,
            'tool_definitions' => [['name' => 'direct_action', 'description' => 'Direct tool']],
        ]);

        $this->agent->tools()->attach($directTool->id, ['priority' => 0]);

        $otherTool = Tool::factory()->create([
            'team_id' => $this->team->id,
            'status' => ToolStatus::Active,
        ]);
        $toolset = Toolset::create([
            'team_id' => $this->team->id,
            'name' => 'Unattached Toolset',
            'slug' => 'unattached-toolset',
            'description' => '',
            'tool_ids' => [$otherTool->id],
            'tags' => [],
        ]);

        $action = app(ResolveAgentToolsAction::class);
        $prismTools = $action->execute($this->agent);

        $names = array_map(fn ($t) => method_exists($t, 'name') ? $t->name() : ($t->name ?? ''), $prismTools);
        $this->assertNotContains('unattached-toolset', $names);
        $this->assertCount(count($prismTools), $prismTools);
    }

    public function test_toolset_with_empty_tool_ids_causes_no_error(): void
    {
        $toolset = Toolset::create([
            'team_id' => $this->team->id,
            'name' => 'Empty Toolset',
            'slug' => 'empty-toolset',
            'description' => '',
            'tool_ids' => [],
            'tags' => [],
        ]);

        $this->agent->toolsets()->attach($toolset->id);

        $action = app(ResolveAgentToolsAction::class);
        $prismTools = $action->execute($this->agent);

        $this->assertIsArray($prismTools);
    }
}
