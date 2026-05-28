<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Domain\Agent\Models\Agent;
use App\Domain\Shared\Models\Team;
use App\Livewire\Agents\AgentWorkspacePage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AgentWorkspacePageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Workspace Test Team',
            'slug' => 'workspace-test-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);

        $this->agent = Agent::factory()->for($this->team)->create([
            'name' => 'Workspace Test Agent',
            'role' => 'Researcher',
            'goal' => 'Find prior art.',
            'backstory' => 'Has read every paper.',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-5',
        ]);
    }

    public function test_default_tab_is_draft(): void
    {
        Livewire::test(AgentWorkspacePage::class, ['agent' => $this->agent])
            ->assertSet('activeTab', 'draft')
            ->assertSee('Workspace Test Agent')
            ->assertSee('Open full editor');
    }

    public function test_tab_switching_keeps_agent_context(): void
    {
        Livewire::test(AgentWorkspacePage::class, ['agent' => $this->agent])
            ->call('setTab', 'test')
            ->assertSet('activeTab', 'test')
            ->assertSee('Open sandbox')
            ->call('setTab', 'deploy')
            ->assertSet('activeTab', 'deploy')
            ->assertSee('Publish to marketplace')
            ->call('setTab', 'script')
            ->assertSet('activeTab', 'script')
            ->assertSee('Script preview')
            ->assertSee('Researcher')
            ->assertSee('Find prior art.');
    }

    public function test_invalid_tab_value_is_ignored(): void
    {
        Livewire::test(AgentWorkspacePage::class, ['agent' => $this->agent])
            ->call('setTab', 'malicious')
            ->assertSet('activeTab', 'draft');
    }

    public function test_script_property_composes_system_prompt_from_role_goal_backstory(): void
    {
        $component = Livewire::test(AgentWorkspacePage::class, ['agent' => $this->agent]);
        $script = $component->instance()->script;

        $this->assertStringContainsString('## Role', $script['system_prompt']);
        $this->assertStringContainsString('Researcher', $script['system_prompt']);
        $this->assertStringContainsString('## Goal', $script['system_prompt']);
        $this->assertStringContainsString('Find prior art.', $script['system_prompt']);
        $this->assertStringContainsString('## Backstory', $script['system_prompt']);
        $this->assertSame('anthropic', $script['provider']);
        $this->assertSame('claude-sonnet-4-5', $script['model']);
    }

    public function test_script_handles_empty_persona_fields(): void
    {
        $blank = Agent::factory()->for($this->team)->create([
            'name' => 'Blank',
            'role' => null,
            'goal' => null,
            'backstory' => null,
        ]);

        $component = Livewire::test(AgentWorkspacePage::class, ['agent' => $blank]);
        $script = $component->instance()->script;

        $this->assertSame('(no role/goal/backstory configured)', $script['system_prompt']);
    }

    public function test_workspace_route_resolves(): void
    {
        $this->get(route('agents.workspace', $this->agent))
            ->assertOk()
            ->assertSee('Workspace Test Agent');
    }
}
