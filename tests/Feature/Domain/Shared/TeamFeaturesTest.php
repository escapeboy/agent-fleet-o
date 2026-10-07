<?php

namespace Tests\Feature\Domain\Shared;

use App\Domain\Agent\Actions\EvaluateAgentConfigGateAction;
use App\Domain\Agent\Models\Agent;
use App\Domain\Shared\Enums\TeamRole;
use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Services\TeamFeatures;
use App\Livewire\Teams\TeamSettingsPage;
use App\Mcp\Tools\Shared\TeamFeatureListTool;
use App\Mcp\Tools\Shared\TeamFeatureSetTool;
use App\Models\User;
use Cloud\Livewire\Teams\CloudTeamSettingsPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Livewire\Livewire;
use Tests\TestCase;

class TeamFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private function teamWith(TeamRole $role, array $settings = []): array
    {
        $user = User::factory()->create();
        $owner = $role === TeamRole::Owner ? $user : User::factory()->create();
        $team = Team::create([
            'name' => 'Features Team',
            'slug' => 'features-team-'.uniqid(),
            'owner_id' => $owner->id,
            'settings' => $settings,
        ]);
        $team->users()->attach($user, ['role' => $role->value]);
        $user->update(['current_team_id' => $team->id]);

        return [$user->fresh(), $team->fresh()];
    }

    /** Base edition allows every user; mirror the cloud role check. */
    private function enforceTeamRoles(): void
    {
        Gate::define('manage-team', fn (User $user) => (bool) $user->teamRole($user->currentTeam)?->canManageTeam());
    }

    private function features(): TeamFeatures
    {
        app()->forgetScopedInstances();

        return app(TeamFeatures::class);
    }

    private function settingsPage(): string
    {
        return class_exists(CloudTeamSettingsPage::class) ? CloudTeamSettingsPage::class : TeamSettingsPage::class;
    }

    public function test_platform_off_wins_over_team_choice(): void
    {
        [, $team] = $this->teamWith(TeamRole::Owner, ['features' => ['semantic_cache' => true]]);
        Config::set('semantic_cache.enabled', false);

        $this->assertFalse($this->features()->enabled('semantic_cache', $team));
    }

    public function test_team_can_turn_off_a_platform_feature(): void
    {
        [, $team] = $this->teamWith(TeamRole::Owner);
        Config::set('semantic_cache.enabled', true);

        $this->assertTrue($this->features()->enabled('semantic_cache', $team->id));

        $this->features()->set($team, 'semantic_cache', false);

        $this->assertFalse($this->features()->enabled('semantic_cache', $team->id));
        $this->assertSame(false, $team->fresh()->settings['features']['semantic_cache']);
    }

    public function test_default_applies_without_a_team_or_a_choice(): void
    {
        Config::set('semantic_cache.enabled', true);

        $this->assertTrue($this->features()->enabled('semantic_cache', null));
        $this->assertFalse($this->features()->enabled('chatbot', null));
    }

    public function test_chatbot_uses_the_legacy_setting_key(): void
    {
        [, $team] = $this->teamWith(TeamRole::Owner, ['chatbot_enabled' => true]);

        $this->assertTrue($this->features()->enabled('chatbot', $team));

        $this->features()->set($team, 'chatbot', false);

        $this->assertFalse($team->fresh()->settings['chatbot_enabled']);
    }

    public function test_unknown_key_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->features()->enabled('no_such_feature', null);
    }

    public function test_read_site_respects_team_opt_out(): void
    {
        [, $team] = $this->teamWith(TeamRole::Owner, ['features' => ['agent_eval_gate' => false]]);
        Config::set('agent.eval_gate.enabled', true);
        $agent = Agent::factory()->create(['team_id' => $team->id]);

        $result = app(EvaluateAgentConfigGateAction::class)->execute($agent, ['goal' => 'changed']);

        $this->assertFalse($result['gated']);
    }

    public function test_owner_toggles_feature_from_settings_page(): void
    {
        [$user, $team] = $this->teamWith(TeamRole::Owner);
        Config::set('loop_detection.enabled', true);
        $this->actingAs($user);

        Livewire::test($this->settingsPage())
            ->call('toggleTeamFeature', 'loop_detection')
            ->assertHasNoErrors();

        $this->assertFalse($team->fresh()->settings['features']['loop_detection']);
    }

    public function test_team_cannot_enable_a_platform_off_feature(): void
    {
        [$user, $team] = $this->teamWith(TeamRole::Owner, ['features' => ['loop_detection' => false]]);
        Config::set('loop_detection.enabled', false);
        $this->actingAs($user);

        Livewire::test($this->settingsPage())->call('toggleTeamFeature', 'loop_detection');

        $this->assertFalse($team->fresh()->settings['features']['loop_detection']);
    }

    public function test_member_cannot_toggle(): void
    {
        $this->enforceTeamRoles();
        [$user, $team] = $this->teamWith(TeamRole::Member);
        Config::set('loop_detection.enabled', true);
        $this->actingAs($user);

        Livewire::test($this->settingsPage())
            ->call('toggleTeamFeature', 'loop_detection')
            ->assertForbidden();

        $this->assertArrayNotHasKey('features', $team->fresh()->settings ?? []);
    }

    public function test_mcp_list_and_set(): void
    {
        [$user, $team] = $this->teamWith(TeamRole::Owner);
        Config::set('semantic_cache.enabled', true);
        $this->actingAs($user);

        $set = json_decode((string) (new TeamFeatureSetTool)->handle(
            new Request(['key' => 'semantic_cache', 'enabled' => false]),
            $this->features(),
        )->content(), true);

        $this->assertFalse($set['enabled']);

        $list = json_decode((string) (new TeamFeatureListTool)->handle(new Request([]), $this->features())->content(), true);
        $row = collect($list['features'])->firstWhere('key', 'semantic_cache');

        $this->assertFalse($row['team_choice']);
        $this->assertTrue($row['platform_enabled']);
        $this->assertFalse($row['enabled']);
    }

    public function test_mcp_set_rejects_member_and_unknown_key(): void
    {
        $this->enforceTeamRoles();
        [$user] = $this->teamWith(TeamRole::Member);
        $this->actingAs($user);

        $denied = (new TeamFeatureSetTool)->handle(new Request(['key' => 'semantic_cache', 'enabled' => false]), $this->features());
        $this->assertTrue($denied->isError());

        [$owner] = $this->teamWith(TeamRole::Owner);
        $this->actingAs($owner);

        $unknown = (new TeamFeatureSetTool)->handle(new Request(['key' => 'nope', 'enabled' => true]), $this->features());
        $this->assertTrue($unknown->isError());
    }

    public function test_mcp_cannot_enable_a_platform_off_feature(): void
    {
        [$user, $team] = $this->teamWith(TeamRole::Owner);
        Config::set('loop_detection.enabled', false);
        $this->actingAs($user);

        $response = (new TeamFeatureSetTool)->handle(new Request(['key' => 'loop_detection', 'enabled' => true]), $this->features());

        $this->assertTrue($response->isError());
        $this->assertArrayNotHasKey('features', $team->fresh()->settings ?? []);
    }
}
