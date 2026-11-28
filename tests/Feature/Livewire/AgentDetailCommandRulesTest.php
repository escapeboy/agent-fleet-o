<?php

namespace Tests\Feature\Livewire;

use App\Domain\Agent\Models\Agent;
use App\Domain\Shared\Models\Team;
use App\Livewire\Agents\AgentDetailPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AgentDetailCommandRulesTest extends TestCase
{
    use RefreshDatabase;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $team = Team::create([
            'name' => 'T',
            'slug' => 't-'.uniqid(),
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $team->id]);
        $this->actingAs($user);
        $this->agent = Agent::factory()->create([
            'team_id' => $team->id,
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-5',
            'config' => ['reasoning_effort' => 'high'],
        ]);
    }

    public function test_start_edit_fills_the_seed_defaults(): void
    {
        Livewire::test(AgentDetailPage::class, ['agent' => $this->agent])
            ->call('startEdit')
            ->assertSet('editCommandRules', "ask: git push\nask: rm -rf\nblock: sudo");
    }

    public function test_start_edit_shows_stored_rules(): void
    {
        $this->agent->update(['config' => ['command_rules' => [['prefix' => 'npm publish', 'action' => 'block']]]]);

        Livewire::test(AgentDetailPage::class, ['agent' => $this->agent->fresh()])
            ->call('startEdit')
            ->assertSet('editCommandRules', 'block: npm publish');
    }

    public function test_save_stores_the_rules(): void
    {
        Livewire::test(AgentDetailPage::class, ['agent' => $this->agent])
            ->call('startEdit')
            ->set('editCommandRules', "block: npm publish\n\n  ask:   git push  \n")
            ->call('save')
            ->assertHasNoErrors();

        $config = $this->agent->fresh()->config;
        $this->assertSame([
            ['prefix' => 'npm publish', 'action' => 'block'],
            ['prefix' => 'git push', 'action' => 'ask'],
        ], $config['command_rules']);
        $this->assertSame('high', $config['reasoning_effort']);
    }

    public function test_saving_the_defaults_leaves_the_key_out(): void
    {
        Livewire::test(AgentDetailPage::class, ['agent' => $this->agent])
            ->call('startEdit')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertArrayNotHasKey('command_rules', $this->agent->fresh()->config);
    }

    public function test_empty_textarea_stores_no_rules(): void
    {
        Livewire::test(AgentDetailPage::class, ['agent' => $this->agent])
            ->call('startEdit')
            ->set('editCommandRules', '')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame([], $this->agent->fresh()->config['command_rules']);
    }

    public function test_invalid_line_shows_an_error_and_writes_nothing(): void
    {
        $originalName = $this->agent->name;

        Livewire::test(AgentDetailPage::class, ['agent' => $this->agent])
            ->call('startEdit')
            ->set('editName', 'Renamed agent')
            ->set('editCommandRules', "ask: git push\nmaybe: ls")
            ->call('save')
            ->assertHasErrors(['editCommandRules']);

        $fresh = $this->agent->fresh();
        $this->assertSame($originalName, $fresh->name);
        $this->assertSame(['reasoning_effort' => 'high'], $fresh->config);
    }
}
