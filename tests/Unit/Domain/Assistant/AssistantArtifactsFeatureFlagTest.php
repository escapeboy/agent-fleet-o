<?php

namespace Tests\Unit\Domain\Assistant;

use App\Domain\Assistant\Services\AssistantArtifactsFeatureFlag;
use App\Domain\Shared\Models\Team;
use App\Models\GlobalSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssistantArtifactsFeatureFlagTest extends TestCase
{
    use RefreshDatabase;

    private AssistantArtifactsFeatureFlag $flag;

    protected function setUp(): void
    {
        parent::setUp();

        $this->flag = new AssistantArtifactsFeatureFlag;
    }

    public function test_globally_enabled_defaults_to_false(): void
    {
        $this->assertFalse($this->flag->isGloballyEnabled());
    }

    public function test_set_global_enabled_flips_flag(): void
    {
        $this->flag->setGlobalEnabled(true);
        $this->assertTrue($this->flag->isGloballyEnabled());

        $this->flag->setGlobalEnabled(false);
        $this->assertFalse($this->flag->isGloballyEnabled());
    }

    public function test_team_without_flag_is_denied_even_if_globally_enabled(): void
    {
        $this->flag->setGlobalEnabled(true);
        $team = $this->makeTeam(allowed: false);

        $this->assertFalse($this->flag->isEnabledForTeam($team));
    }

    public function test_team_with_flag_is_denied_when_globally_disabled(): void
    {
        $this->flag->setGlobalEnabled(false);
        $team = $this->makeTeam(allowed: true);

        $this->assertFalse($this->flag->isEnabledForTeam($team));
    }

    public function test_both_flags_set_grants_access(): void
    {
        $this->flag->setGlobalEnabled(true);
        $team = $this->makeTeam(allowed: true);

        $this->assertTrue($this->flag->isEnabledForTeam($team));
    }

    public function test_null_team_is_always_denied(): void
    {
        $this->flag->setGlobalEnabled(true);

        $this->assertFalse($this->flag->isEnabledForTeam(null));
    }

    public function test_legacy_scalar_global_setting_value_is_respected(): void
    {
        // In case someone set the key as a raw boolean instead of the new
        // ['enabled' => bool] shape — we still want to handle it gracefully.
        GlobalSetting::set('assistant.ui_artifacts_enabled', true);
        $this->assertTrue($this->flag->isGloballyEnabled());
    }

    private function makeTeam(bool $allowed): Team
    {
        $owner = User::factory()->create();

        return Team::create([
            'name' => 'Test '.bin2hex(random_bytes(3)),
            'slug' => 'test-'.bin2hex(random_bytes(3)),
            'owner_id' => $owner->id,
            'assistant_ui_artifacts_allowed' => $allowed,
        ]);
    }
}
