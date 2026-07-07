<?php

namespace Tests\Unit\Domain\GitRepository;

use App\Domain\GitRepository\Services\WarmRepoManager;
use App\Domain\Shared\Models\Team;
use Tests\TestCase;

/**
 * Pins the warm-build activation gate — a security boundary: an untrusted
 * tenant must NEVER get the warm-build sandbox by the global flag alone. Pure
 * config + model logic, no git shell-out (runs anywhere, unlike the Process
 * integration tests which require real git on the runner).
 */
class WarmRepoManagerGateTest extends TestCase
{
    public function test_global_off_denies_even_an_allowed_team(): void
    {
        config(['experiments.warm_build.enabled' => false]);

        $this->assertFalse(WarmRepoManager::enabled());
        $this->assertFalse(WarmRepoManager::enabledForTeam(new Team(['warm_build_allowed' => true])));
    }

    public function test_global_on_but_null_team_is_denied(): void
    {
        config(['experiments.warm_build.enabled' => true]);

        $this->assertFalse(WarmRepoManager::enabledForTeam(null));
    }

    public function test_global_on_but_untrusted_team_is_denied(): void
    {
        config(['experiments.warm_build.enabled' => true]);

        $this->assertFalse(WarmRepoManager::enabledForTeam(new Team(['warm_build_allowed' => false])));
    }

    public function test_missing_flag_defaults_to_denied(): void
    {
        config(['experiments.warm_build.enabled' => true]);

        // A team that never had warm_build_allowed set must not be trusted.
        $this->assertFalse(WarmRepoManager::enabledForTeam(new Team));
    }

    public function test_global_on_and_trusted_team_is_allowed(): void
    {
        config(['experiments.warm_build.enabled' => true]);

        $this->assertTrue(WarmRepoManager::enabledForTeam(new Team(['warm_build_allowed' => true])));
    }
}
