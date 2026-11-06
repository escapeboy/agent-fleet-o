<?php

namespace Tests\Feature\Marketplace;

use App\Domain\Marketplace\Actions\InstallFromMarketplaceAction;
use App\Domain\Marketplace\Models\MarketplaceListing;
use App\Domain\Shared\Models\Team;
use App\Domain\Skill\Enums\RiskLevel;
use App\Domain\Skill\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstallLegacyRiskLevelTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    private InstallFromMarketplaceAction $action;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);

        $this->action = app(InstallFromMarketplaceAction::class);
    }

    public function test_installs_skill_listing_with_safe_risk_level(): void
    {
        $listing = MarketplaceListing::factory()->create([
            'team_id' => $this->team->id,
            'published_by' => $this->user->id,
            'type' => 'skill',
            'configuration_snapshot' => ['type' => 'llm', 'risk_level' => 'safe'],
        ]);

        $installation = $this->action->execute($listing, $this->team->id, $this->user->id);

        $skill = Skill::withoutGlobalScopes()->findOrFail($installation->installed_skill_id);
        $this->assertSame(RiskLevel::Low, $skill->risk_level);
    }

    public function test_execute_from_manifest_accepts_safe_risk_level(): void
    {
        $skill = $this->action->executeFromManifest(
            'skill',
            ['type' => 'llm', 'risk_level' => 'safe'],
            'Legacy Skill',
            '1.0.0',
            $this->team->id,
            $this->user->id,
        );

        $this->assertInstanceOf(Skill::class, $skill);
        $this->assertSame(RiskLevel::Low, $skill->fresh()->risk_level);
    }

    public function test_unknown_risk_level_falls_back_to_high(): void
    {
        $skill = $this->action->executeFromManifest(
            'skill',
            ['type' => 'llm', 'risk_level' => 'bogus'],
            'Unknown Risk Skill',
            '1.0.0',
            $this->team->id,
            $this->user->id,
        );

        $risk = $skill->fresh()->risk_level;
        $this->assertSame(RiskLevel::High, $risk);
        $this->assertTrue($risk->requiresApproval());
    }
}
