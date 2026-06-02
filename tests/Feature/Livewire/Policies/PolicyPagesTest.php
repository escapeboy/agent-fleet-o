<?php

namespace Tests\Feature\Livewire\Policies;

use App\Domain\Agent\Actions\CreateAgentPolicyAction;
use App\Domain\Agent\Actions\UpdateAgentPolicyAction;
use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentPolicy;
use App\Domain\Agent\Models\AgentPolicyVersion;
use App\Domain\Shared\Models\Team;
use App\Livewire\Policies\CreatePolicyForm;
use App\Livewire\Policies\PolicyDetailPage;
use App\Livewire\Policies\PolicyListPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PolicyPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'T '.bin2hex(random_bytes(3)),
            'slug' => 't-'.bin2hex(random_bytes(3)),
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);
    }

    public function test_list_renders_team_policies(): void
    {
        app(CreateAgentPolicyAction::class)->execute(teamId: $this->team->id, name: 'Visible Policy');

        Livewire::test(PolicyListPage::class)
            ->assertOk()
            ->assertSee('Visible Policy');
    }

    public function test_create_form_builds_rules_and_persists(): void
    {
        Livewire::test(CreatePolicyForm::class)
            ->set('name', 'My Policy')
            ->set('riskCeiling', 'low')
            ->set('autoExecuteEnabled', true)
            ->set('autoExecuteThreshold', 20)
            ->set('deniedTargetTypes', 'migration, git_push')
            ->set('sensitivePaths', "app/**/Auth/**\n**/Billing/**")
            ->set('spendCapCredits', 500)
            ->set('enabled', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $policy = AgentPolicy::where('name', 'My Policy')->first();
        $this->assertNotNull($policy);
        $this->assertTrue($policy->enabled);

        $rules = $policy->currentVersion->rules;
        $this->assertSame('low', $rules['risk_ceiling']);
        $this->assertTrue($rules['auto_execute']['enabled']);
        $this->assertSame(['migration', 'git_push'], $rules['denied_target_types']);
        $this->assertSame(['app/**/Auth/**', '**/Billing/**'], $rules['sensitive_paths']);
        $this->assertSame(500, $rules['spend_cap']['credits']);
    }

    public function test_detail_toggle_enables_policy(): void
    {
        $policy = app(CreateAgentPolicyAction::class)->execute(teamId: $this->team->id, name: 'P');
        $this->assertFalse($policy->enabled);

        Livewire::test(PolicyDetailPage::class, ['policy' => $policy])
            ->call('toggleEnabled');

        $this->assertTrue($policy->fresh()->enabled);
    }

    public function test_detail_aborts_403_for_other_teams_policy(): void
    {
        $otherUser = User::factory()->create();
        $otherTeam = Team::create([
            'name' => 'O '.bin2hex(random_bytes(3)),
            'slug' => 'o-'.bin2hex(random_bytes(3)),
            'owner_id' => $otherUser->id,
            'settings' => [],
        ]);
        $foreign = app(CreateAgentPolicyAction::class)->execute(teamId: $otherTeam->id, name: 'Foreign');

        // Invoke the ownership guard directly (harness-independent): mounting
        // another team's policy must 403.
        $this->expectException(HttpException::class);
        (new PolicyDetailPage)->mount($foreign);
    }

    public function test_create_rejects_agent_id_from_another_team(): void
    {
        $otherUser = User::factory()->create();
        $otherTeam = Team::create([
            'name' => 'O '.bin2hex(random_bytes(3)),
            'slug' => 'o-'.bin2hex(random_bytes(3)),
            'owner_id' => $otherUser->id,
            'settings' => [],
        ]);
        $foreignAgent = Agent::factory()->create(['team_id' => $otherTeam->id]);

        Livewire::test(CreatePolicyForm::class)
            ->set('name', 'X')
            ->set('agentId', $foreignAgent->id)
            ->call('save')
            ->assertHasErrors('agentId');
    }

    public function test_detail_rollback_mints_new_version(): void
    {
        $policy = app(CreateAgentPolicyAction::class)->execute(
            teamId: $this->team->id, name: 'P', rules: ['risk_ceiling' => 'low'],
        );
        $v1Id = $policy->current_version_id;
        app(UpdateAgentPolicyAction::class)->execute($policy, rules: ['risk_ceiling' => 'critical']);

        Livewire::test(PolicyDetailPage::class, ['policy' => $policy->fresh()])
            ->call('rollback', $v1Id);

        $this->assertSame(3, AgentPolicyVersion::where('agent_policy_id', $policy->id)->max('version'));
        $this->assertSame('low', $policy->fresh()->currentVersion->rules['risk_ceiling']);
    }
}
