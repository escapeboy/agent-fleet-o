<?php

namespace Tests\Feature\Livewire\Settings;

use App\Livewire\Settings\SecurityPolicyPanel;
use App\Models\GlobalSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SecurityPolicyPanelSqlGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The security policy panel is gated on self-hosted mode.
        config(['app.deployment_mode' => 'self_hosted', 'cloud.mode' => false]);
        $this->actingAs(User::factory()->create());
    }

    public function test_disabling_sql_guard_persists_false(): void
    {
        Livewire::test(SecurityPolicyPanel::class)
            ->set('sqlGuardEnabled', false)
            ->call('save');

        $policy = GlobalSetting::get('org_security_policy', []);
        $this->assertFalse($policy['sql_guard_enabled']);
    }

    public function test_enabled_sql_guard_is_not_stored_as_noise(): void
    {
        Livewire::test(SecurityPolicyPanel::class)
            ->set('sqlGuardEnabled', true)
            ->call('save');

        $policy = GlobalSetting::get('org_security_policy', []);
        $this->assertArrayNotHasKey('sql_guard_enabled', $policy);
    }

    public function test_custom_sql_patterns_persist(): void
    {
        Livewire::test(SecurityPolicyPanel::class)
            ->set('requireApprovalSqlPatterns', "vacuum full\nreindex")
            ->call('save');

        $policy = GlobalSetting::get('org_security_policy', []);
        $this->assertSame(['vacuum full', 'reindex'], $policy['require_approval_sql_patterns']);
    }

    public function test_renders_without_error(): void
    {
        Livewire::test(SecurityPolicyPanel::class)
            ->set('editing', true)
            ->assertOk()
            ->assertSee('Destructive SQL guard');
    }
}
