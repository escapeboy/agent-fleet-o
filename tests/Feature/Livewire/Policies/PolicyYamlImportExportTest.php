<?php

namespace Tests\Feature\Livewire\Policies;

use App\Domain\Agent\Actions\CreateAgentPolicyAction;
use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentPolicy;
use App\Domain\Agent\Models\AgentPolicyVersion;
use App\Domain\Agent\Support\AgentPolicyYamlSerializer;
use App\Domain\Shared\Models\Team;
use App\Livewire\Policies\CreatePolicyForm;
use App\Livewire\Policies\PolicyDetailPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class PolicyYamlImportExportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->user, $this->team] = $this->makeUserWithTeam('A');
        $this->actingAs($this->user);
    }

    /**
     * @return array{0: User, 1: Team}
     */
    private function makeUserWithTeam(string $prefix): array
    {
        $user = User::factory()->create();
        $team = Team::create([
            'name' => $prefix.' '.bin2hex(random_bytes(3)),
            'slug' => strtolower($prefix).'-'.bin2hex(random_bytes(3)),
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $team->id]);
        $team->users()->attach($user, ['role' => 'owner']);

        return [$user->fresh(), $team];
    }

    /**
     * @return array<string, mixed>
     */
    private function customRules(): array
    {
        return [
            'allowed_target_types' => ['tool_call'],
            'denied_target_types' => ['migration', 'git_push'],
            'risk_ceiling' => 'low',
            'auto_execute' => ['enabled' => true, 'threshold' => 20],
            'spend_cap' => ['credits' => 500, 'window' => 'week'],
            'frequency_cap' => ['count' => 10, 'window' => 'hour'],
            'sensitive_paths' => ['app/**/Auth/**'],
        ];
    }

    private function exportedYaml(AgentPolicy $policy): string
    {
        $component = Livewire::test(PolicyDetailPage::class, ['policy' => $policy])
            ->call('downloadYaml')
            ->assertFileDownloaded(Str::slug($policy->name).'-v1.policy.yaml');

        return base64_decode($component->effects['download']['content']);
    }

    public function test_download_yaml_returns_current_rules_and_name(): void
    {
        $policy = app(CreateAgentPolicyAction::class)->execute(
            teamId: $this->team->id, name: 'Exported Policy', rules: $this->customRules(),
        );

        $doc = Yaml::parse($this->exportedYaml($policy));

        $this->assertSame('fleetq.io/v1', $doc['apiVersion']);
        $this->assertSame('AgentPolicy', $doc['kind']);
        $this->assertSame('Exported Policy', $doc['metadata']['name']);
        $this->assertSame($this->customRules(), $doc['rules']);
        $this->assertArrayNotHasKey('team_id', $doc);
        $this->assertSame(1, AgentPolicyVersion::where('agent_policy_id', $policy->id)->count());
    }

    public function test_export_then_import_on_create_form_makes_disabled_v1_policy(): void
    {
        $source = app(CreateAgentPolicyAction::class)->execute(
            teamId: $this->team->id, name: 'Exported Policy', rules: $this->customRules(), enabled: true,
        );
        $yaml = $this->exportedYaml($source);

        Livewire::test(CreatePolicyForm::class)
            ->set('name', 'Imported Copy')
            ->set('yamlImport', $yaml)
            ->call('importYaml')
            ->assertHasNoErrors()
            ->assertRedirect();

        $imported = AgentPolicy::where('name', 'Imported Copy')->first();
        $this->assertNotNull($imported);
        $this->assertSame($this->team->id, $imported->team_id);
        $this->assertFalse($imported->enabled);
        $this->assertNull($imported->agent_id);
        $this->assertSame(1, $imported->currentVersion->version);
        $this->assertSame($this->customRules(), $imported->currentVersion->rules);
    }

    public function test_import_on_create_form_uses_metadata_name_when_form_name_is_empty(): void
    {
        $yaml = (new AgentPolicyYamlSerializer)->serializeRules('From Yaml', $this->customRules());

        Livewire::test(CreatePolicyForm::class)
            ->set('yamlImport', $yaml)
            ->call('importYaml')
            ->assertHasNoErrors();

        $this->assertNotNull(AgentPolicy::where('name', 'From Yaml')->first());
    }

    public function test_import_on_create_form_requires_a_name(): void
    {
        Livewire::test(CreatePolicyForm::class)
            ->set('yamlImport', "apiVersion: fleetq.io/v1\nkind: AgentPolicy\nrules:\n  risk_ceiling: low\n")
            ->call('importYaml')
            ->assertHasErrors('name');

        $this->assertSame(0, AgentPolicy::count());
    }

    public function test_import_on_create_form_rejects_unknown_keys_and_creates_nothing(): void
    {
        Livewire::test(CreatePolicyForm::class)
            ->set('name', 'Bad')
            ->set('yamlImport', "apiVersion: fleetq.io/v1\nkind: AgentPolicy\nrules:\n  bypass_all: true\n")
            ->call('importYaml')
            ->assertHasErrors('yamlImport');

        $this->assertSame(0, AgentPolicy::count());
    }

    public function test_import_on_create_form_rejects_agent_from_another_team(): void
    {
        [, $otherTeam] = $this->makeUserWithTeam('B');
        $foreignAgent = Agent::factory()->create(['team_id' => $otherTeam->id]);
        $yaml = (new AgentPolicyYamlSerializer)->serializeRules('X', $this->customRules());

        Livewire::test(CreatePolicyForm::class)
            ->set('agentId', $foreignAgent->id)
            ->set('yamlImport', $yaml)
            ->call('importYaml')
            ->assertHasErrors('agentId');

        $this->assertSame(0, AgentPolicy::count());
    }

    public function test_yaml_exported_by_one_team_imports_into_the_importers_team(): void
    {
        $source = app(CreateAgentPolicyAction::class)->execute(
            teamId: $this->team->id, name: 'Shared', rules: $this->customRules(),
        );
        $yaml = $this->exportedYaml($source);

        [$otherUser, $otherTeam] = $this->makeUserWithTeam('B');
        $this->actingAs($otherUser);

        Livewire::test(CreatePolicyForm::class)
            ->set('yamlImport', $yaml)
            ->call('importYaml')
            ->assertHasNoErrors();

        $imported = AgentPolicy::withoutGlobalScopes()
            ->where('team_id', $otherTeam->id)
            ->where('name', 'Shared')
            ->first();
        $this->assertNotNull($imported);
        $this->assertSame($this->customRules(), $imported->currentVersion->rules);
        // The source team's policy is untouched.
        $this->assertSame(1, AgentPolicyVersion::withoutGlobalScopes()->where('agent_policy_id', $source->id)->count());
    }

    public function test_import_on_detail_page_mints_new_version_and_keeps_name_and_enabled(): void
    {
        $policy = app(CreateAgentPolicyAction::class)->execute(
            teamId: $this->team->id, name: 'Keep Me', enabled: true,
        );
        $v1Id = $policy->current_version_id;
        $yaml = (new AgentPolicyYamlSerializer)->serializeRules('Other Name', $this->customRules());

        Livewire::test(PolicyDetailPage::class, ['policy' => $policy])
            ->set('yamlImport', $yaml)
            ->call('importYaml')
            ->assertHasNoErrors()
            ->assertSet('yamlImport', '');

        $fresh = $policy->fresh();
        $this->assertSame('Keep Me', $fresh->name);
        $this->assertTrue($fresh->enabled);
        $this->assertSame(2, $fresh->currentVersion->version);
        $this->assertSame('Imported from YAML', $fresh->currentVersion->notes);
        $this->assertSame($this->customRules(), $fresh->currentVersion->rules);

        // Rollback still works after an import.
        Livewire::test(PolicyDetailPage::class, ['policy' => $fresh])
            ->call('rollback', $v1Id);
        $this->assertSame('medium', $policy->fresh()->currentVersion->rules['risk_ceiling']);
    }

    public function test_invalid_import_on_detail_page_adds_no_version(): void
    {
        $policy = app(CreateAgentPolicyAction::class)->execute(teamId: $this->team->id, name: 'P');

        Livewire::test(PolicyDetailPage::class, ['policy' => $policy])
            ->set('yamlImport', "apiVersion: fleetq.io/v1\nkind: AgentPolicy\nrules:\n  risk_ceiling: yolo\n")
            ->call('importYaml')
            ->assertHasErrors('yamlImport');

        $this->assertSame(1, AgentPolicyVersion::where('agent_policy_id', $policy->id)->count());
    }
}
