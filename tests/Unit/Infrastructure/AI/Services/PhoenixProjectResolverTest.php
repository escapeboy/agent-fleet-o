<?php

namespace Tests\Unit\Infrastructure\AI\Services;

use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Services\PhoenixProjectResolver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhoenixProjectResolverTest extends TestCase
{
    use RefreshDatabase;

    private function resolver(): PhoenixProjectResolver
    {
        return app(PhoenixProjectResolver::class);
    }

    private function makeTeam(string $slug): Team
    {
        $user = User::factory()->create();

        return Team::create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'owner_id' => $user->id,
            'settings' => [],
        ]);
    }

    public function test_returns_base_project_when_per_team_disabled(): void
    {
        config(['llmops.phoenix.project' => 'fleetq', 'llmops.phoenix.project_per_team' => false]);
        $team = $this->makeTeam('acme');

        $this->assertSame('fleetq', $this->resolver()->resolve($team->id));
    }

    public function test_returns_base_project_when_team_id_null(): void
    {
        config(['llmops.phoenix.project' => 'fleetq', 'llmops.phoenix.project_per_team' => true]);

        $this->assertSame('fleetq', $this->resolver()->resolve(null));
    }

    public function test_returns_per_team_project_when_enabled(): void
    {
        config(['llmops.phoenix.project' => 'fleetq', 'llmops.phoenix.project_per_team' => true]);
        $team = $this->makeTeam('acme');

        $this->assertSame('fleetq-acme', $this->resolver()->resolve($team->id));
    }

    public function test_falls_back_to_team_id_prefix_for_unknown_team(): void
    {
        config(['llmops.phoenix.project' => 'fleetq', 'llmops.phoenix.project_per_team' => true]);
        $missingId = '01999999-9999-7999-8999-999999999999';

        $this->assertSame('fleetq-'.substr($missingId, 0, 8), $this->resolver()->resolve($missingId));
    }
}
