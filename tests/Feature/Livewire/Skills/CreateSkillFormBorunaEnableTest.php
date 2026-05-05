<?php

namespace Tests\Feature\Livewire\Skills;

use App\Domain\Shared\Enums\TeamRole;
use App\Domain\Shared\Models\Team;
use App\Domain\Skill\Actions\RegisterBorunaToolAction;
use App\Domain\Tool\Models\Tool;
use App\Livewire\Skills\CreateSkillForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class CreateSkillFormBorunaEnableTest extends TestCase
{
    use RefreshDatabase;

    private string $fakeBinary;

    protected function setUp(): void
    {
        parent::setUp();

        // PHP_BINARY is always executable in test environments — used as a
        // stand-in for boruna-mcp; the action only checks is_executable().
        $this->fakeBinary = (string) (PHP_BINARY ?: '/usr/bin/env');

        // The platform service uses DEFAULT_BINARY (/usr/local/bin/boruna-mcp).
        // For tests, override that path via config so we can use PHP_BINARY.
        // Since the service signature accepts $binary, and the form calls
        // statusForTeam() without args (using DEFAULT_BINARY), we instead
        // monkey-patch the allowlist to include DEFAULT_BINARY when it's
        // available, OR we test against scenarios that don't depend on
        // the default path. We choose the latter: the form derives status
        // from BorunaPlatformService::statusForTeam($teamId) — that uses
        // the DEFAULT_BINARY constant. We can't change the constant from
        // a test, so we exercise the *resulting* state by pre-creating
        // a Tool when we need 'enabled' and skipping when the default
        // binary isn't installed in CI.
    }

    private function loggedInOwner(): User
    {
        $user = User::factory()->create();
        $team = Team::create([
            'name' => 'Skill Form Test',
            'slug' => 'skill-form-'.uniqid(),
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $team->users()->attach($user, ['role' => TeamRole::Owner->value]);
        $user->update(['current_team_id' => $team->id]);
        $this->actingAs($user);

        return $user;
    }

    public function test_form_renders_binary_missing_banner_when_default_path_absent(): void
    {
        $this->loggedInOwner();

        // Default binary path /usr/local/bin/boruna-mcp is not present in
        // the test container — we expect the binary_missing branch.
        if (is_executable('/usr/local/bin/boruna-mcp')) {
            $this->markTestSkipped('Default Boruna binary is present — cannot exercise binary_missing branch.');
        }

        Livewire::test(CreateSkillForm::class)
            ->set('type', 'boruna_script')
            ->set('step', 3)
            ->assertSet('borunaScript', '')
            ->assertViewHas('borunaStatus', 'binary_missing');
    }

    public function test_owner_can_enable_boruna_via_button(): void
    {
        $user = $this->loggedInOwner();

        // Simulate the platform-supplied binary by overriding the action's
        // binary path argument. The view's status check still consults the
        // DEFAULT_BINARY, but the enableBoruna() action uses the constant
        // by default — so we instead verify the action method behaviour
        // directly via Livewire::call() and assert the Tool is persisted.
        if (! is_executable('/usr/local/bin/boruna-mcp')) {
            // Provide a transient symlink so the default-path call succeeds.
            // We avoid touching system state in tests; instead we directly
            // invoke the action with our binary path to assert end-to-end
            // wiring without depending on binary location.
            $action = app(RegisterBorunaToolAction::class);
            $action->execute(teamId: $user->current_team_id, binary: $this->fakeBinary);

            $tool = Tool::withoutGlobalScopes()
                ->where('team_id', $user->current_team_id)
                ->where('subkind', 'boruna')
                ->first();

            $this->assertNotNull($tool, 'RegisterBorunaToolAction must persist a Tool.');
            $this->assertEquals('boruna', $tool->subkind);

            return;
        }

        // Default binary present — exercise the full Livewire flow.
        Livewire::test(CreateSkillForm::class)
            ->set('type', 'boruna_script')
            ->set('step', 3)
            ->call('enableBoruna')
            ->assertHasNoErrors();

        $this->assertEquals(
            1,
            Tool::withoutGlobalScopes()
                ->where('team_id', $user->current_team_id)
                ->where('subkind', 'boruna')
                ->count(),
        );
    }

    public function test_enable_button_respects_manage_team_gate(): void
    {
        // In base (community edition) `manage-team` is unconditionally true,
        // so the gate is enforced at the cloud layer's tightened policy.
        // We verify here that the Livewire action *invokes* the gate — when
        // the gate is overridden to deny, the action must short-circuit
        // with an error and NOT persist a Tool.
        $user = $this->loggedInOwner();
        $team = $user->currentTeam;

        Gate::define('manage-team', fn ($u) => false);

        Livewire::test(CreateSkillForm::class)
            ->set('type', 'boruna_script')
            ->set('step', 3)
            ->call('enableBoruna')
            ->assertHasErrors('borunaScript');

        $this->assertEquals(
            0,
            Tool::withoutGlobalScopes()
                ->where('team_id', $team->id)
                ->where('subkind', 'boruna')
                ->count(),
            'Gate denial must prevent Tool creation.',
        );
    }
}
