<?php

namespace Tests\Feature\FeatureFlag;

use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Audit\Models\AuditEntry;
use App\Domain\FeatureFlag\Actions\ArchiveFeatureFlagAction;
use App\Domain\FeatureFlag\Actions\SetFeatureFlagAction;
use App\Domain\FeatureFlag\Actions\SetFeatureRolloutAction;
use App\Domain\FeatureFlag\Models\FeatureFlagRollout;
use App\Domain\FeatureFlag\Services\FeatureFlagService;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Tests\TestCase;

class FeatureFlagActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['feature_flags.runtime_enabled' => true]);
    }

    private function makeTeam(): Team
    {
        $user = User::factory()->create();

        return Team::create([
            'name' => 'T',
            'slug' => 'ff-'.uniqid(),
            'owner_id' => $user->id,
            'settings' => [],
        ]);
    }

    private function defineSensitiveFlag(): void
    {
        config(['feature_flags.definitions.sensitive_demo' => [
            'label' => 'Sensitive Demo',
            'description' => 'test',
            'group' => 'Demo',
            'sensitive' => true,
            'default' => false,
        ]]);
        app(FeatureFlagService::class)->defineAll();
    }

    public function test_non_sensitive_toggle_applies_and_is_audited(): void
    {
        $team = $this->makeTeam();

        $result = app(SetFeatureFlagAction::class)->execute('beta_feature', true, $team);

        $this->assertSame('applied', $result->status);
        $this->assertTrue(Feature::for($team)->active('beta_feature'));
        $this->assertTrue(
            AuditEntry::withoutGlobalScopes()->where('event', 'feature_flag.updated')->exists(),
        );
    }

    public function test_sensitive_flip_by_non_super_admin_creates_approval_and_does_not_apply(): void
    {
        $this->defineSensitiveFlag();
        $team = $this->makeTeam();
        $actor = User::factory()->create(['is_super_admin' => false]);

        $result = app(SetFeatureFlagAction::class)->execute('sensitive_demo', true, $team, $actor);

        $this->assertTrue($result->isPendingApproval());
        $this->assertNotNull($result->approvalId);
        $this->assertFalse(Feature::for($team)->active('sensitive_demo'));
        $this->assertTrue(
            ApprovalRequest::withoutGlobalScopes()->where('context->type', 'feature_flag_change')->exists(),
        );
    }

    public function test_sensitive_flip_by_super_admin_applies_directly(): void
    {
        $this->defineSensitiveFlag();
        $team = $this->makeTeam();
        $actor = User::factory()->create(['is_super_admin' => true]);

        $result = app(SetFeatureFlagAction::class)->execute('sensitive_demo', true, $team, $actor);

        $this->assertSame('applied', $result->status);
        $this->assertTrue(Feature::for($team)->active('sensitive_demo'));
    }

    public function test_rollout_is_clamped_and_audited(): void
    {
        $result = app(SetFeatureRolloutAction::class)->execute('beta_feature', 150);

        $this->assertSame(100, $result->percentage);
        $this->assertSame(100, (int) FeatureFlagRollout::where('key', 'beta_feature')->value('percentage'));
        $this->assertTrue(
            AuditEntry::withoutGlobalScopes()->where('event', 'feature_flag.rollout')->exists(),
        );
    }

    public function test_archive_purges_overrides_and_rollout(): void
    {
        $team = $this->makeTeam();
        Feature::for($team)->activate('beta_feature');
        app(SetFeatureRolloutAction::class)->execute('beta_feature', 50);

        app(ArchiveFeatureFlagAction::class)->execute('beta_feature');
        Feature::flushCache();

        $this->assertFalse(app(FeatureFlagService::class)->active('beta_feature', $team));
        $this->assertSame(0, FeatureFlagRollout::where('key', 'beta_feature')->count());
        $this->assertTrue(
            AuditEntry::withoutGlobalScopes()->where('event', 'feature_flag.archived')->exists(),
        );
    }
}
