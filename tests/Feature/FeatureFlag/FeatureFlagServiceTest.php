<?php

namespace Tests\Feature\FeatureFlag;

use App\Domain\FeatureFlag\Actions\SetFeatureRolloutAction;
use App\Domain\FeatureFlag\Exceptions\UnknownFeatureFlagException;
use App\Domain\FeatureFlag\Services\FeatureFlagService;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Tests\TestCase;

class FeatureFlagServiceTest extends TestCase
{
    use RefreshDatabase;

    private FeatureFlagService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(FeatureFlagService::class);
    }

    private function makeTeam(string $slug): Team
    {
        $user = User::factory()->create();

        return Team::create([
            'name' => 'T '.$slug,
            'slug' => 'ff-'.$slug.'-'.uniqid(),
            'owner_id' => $user->id,
            'settings' => [],
        ]);
    }

    public function test_meta_flag_off_returns_static_default_even_with_stored_override(): void
    {
        config(['feature_flags.runtime_enabled' => true]);
        $team = $this->makeTeam('a');
        Feature::for($team)->activate('beta_feature');

        config(['feature_flags.runtime_enabled' => false]);
        Feature::flushCache();

        $this->assertFalse($this->service->active('beta_feature', $team));
    }

    public function test_meta_flag_on_no_override_zero_rollout_returns_default(): void
    {
        config(['feature_flags.runtime_enabled' => true]);
        $team = $this->makeTeam('b');

        $this->assertFalse($this->service->active('beta_feature', $team));
    }

    public function test_per_team_override_wins_over_rollout(): void
    {
        config(['feature_flags.runtime_enabled' => true]);
        $teamA = $this->makeTeam('on');
        $teamB = $this->makeTeam('off');

        Feature::for($teamA)->activate('beta_feature');

        $this->assertTrue($this->service->active('beta_feature', $teamA));
        $this->assertFalse($this->service->active('beta_feature', $teamB));
    }

    public function test_rollout_100_enables_all_and_0_returns_default(): void
    {
        config(['feature_flags.runtime_enabled' => true]);
        $team = $this->makeTeam('c');

        app(SetFeatureRolloutAction::class)->execute('beta_feature', 100);
        Feature::purge('beta_feature');
        Feature::flushCache();
        $this->assertTrue($this->service->active('beta_feature', $team));

        app(SetFeatureRolloutAction::class)->execute('beta_feature', 0);
        Feature::purge('beta_feature');
        Feature::flushCache();
        $this->assertFalse($this->service->active('beta_feature', $team));
    }

    public function test_rollout_bucket_is_monotonic(): void
    {
        config(['feature_flags.runtime_enabled' => true]);
        $teams = collect(range(1, 30))->map(fn ($i) => $this->makeTeam("m{$i}"));

        $enabledAt = function (int $pct) use ($teams): array {
            app(SetFeatureRolloutAction::class)->execute('beta_feature', $pct);
            Feature::purge('beta_feature');
            Feature::flushCache();

            return $teams->filter(fn (Team $t) => $this->service->active('beta_feature', $t))
                ->map->id->values()->all();
        };

        $at10 = $enabledAt(10);
        $at50 = $enabledAt(50);

        $this->assertEmpty(array_diff($at10, $at50), 'Teams enabled at 10% must remain enabled at 50%.');
    }

    public function test_unknown_flag_throws(): void
    {
        $this->expectException(UnknownFeatureFlagException::class);
        $this->service->definition('does_not_exist');
    }

    public function test_scope_isolation_between_teams(): void
    {
        config(['feature_flags.runtime_enabled' => true]);
        $teamA = $this->makeTeam('iso-a');
        $teamB = $this->makeTeam('iso-b');

        Feature::for($teamA)->activate('beta_feature');

        $this->assertTrue($this->service->active('beta_feature', $teamA));
        $this->assertFalse($this->service->active('beta_feature', $teamB));
    }
}
