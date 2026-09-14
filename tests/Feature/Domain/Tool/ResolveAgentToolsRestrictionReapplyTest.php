<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Tool;

use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use App\Domain\Shared\Models\Team;
use App\Domain\Tool\Actions\ResolveAgentToolsAction;
use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Models\Tool;
use App\Domain\Tool\Models\Toolset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Toolsets, federation and tool search add tool rows after the permission
 * filter. A pivot deny, the agent deny list and the crew allowlist must still
 * hold for those rows.
 */
class ResolveAgentToolsRestrictionReapplyTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private Agent $agent;

    private Tool $planTool;

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent.planning_tool.enabled' => true]);

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Restriction Team',
            'slug' => 'restriction-'.Str::lower(Str::random(6)),
            'owner_id' => $user->id,
            'settings' => [],
        ]);

        $this->agent = Agent::create([
            'id' => (string) Str::uuid7(),
            'team_id' => $this->team->id,
            'name' => 'Restricted Agent',
            'slug' => 'restricted-agent',
            'role' => 'assistant',
            'goal' => 'help',
            'backstory' => 'test',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet',
            'status' => AgentStatus::Active,
            'config' => [],
        ]);

        $this->planTool = Tool::factory()->create([
            'team_id' => $this->team->id,
            'type' => ToolType::BuiltIn,
            'status' => ToolStatus::Active,
            'transport_config' => ['kind' => 'plan'],
        ]);

        $toolset = Toolset::create([
            'team_id' => $this->team->id,
            'name' => 'Planning',
            'slug' => 'planning-'.Str::lower(Str::random(6)),
            'description' => '',
            'tool_ids' => [$this->planTool->id],
            'tags' => [],
        ]);
        $this->agent->toolsets()->attach($toolset->id);
    }

    /**
     * @param  array<int, string>|null  $allowedToolIds
     * @return array<int, string>
     */
    private function resolvedNames(?array $allowedToolIds = null): array
    {
        $tools = app(ResolveAgentToolsAction::class)->execute(
            $this->agent->fresh(), null, null, null, 0, null, null, $allowedToolIds,
        );

        return array_map(fn ($t) => $t->name(), $tools);
    }

    public function test_toolset_adds_the_tool_when_nothing_restricts_it(): void
    {
        $this->assertContains('update_plan', $this->resolvedNames());
    }

    public function test_pivot_denied_tool_is_not_readded_by_a_toolset(): void
    {
        $this->agent->tools()->attach($this->planTool->id, ['priority' => 0, 'approval_mode' => 'deny']);

        $this->assertNotContains('update_plan', $this->resolvedNames());
    }

    public function test_deny_listed_tool_is_not_readded_by_a_toolset(): void
    {
        $this->agent->update(['tool_deny_list' => [$this->planTool->id]]);

        $this->assertNotContains('update_plan', $this->resolvedNames());
    }

    public function test_crew_allowlist_applies_to_toolset_tools(): void
    {
        $this->assertNotContains('update_plan', $this->resolvedNames([(string) Str::uuid7()]));
        $this->assertContains('update_plan', $this->resolvedNames([(string) $this->planTool->id]));
    }
}
