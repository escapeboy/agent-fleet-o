<?php

namespace Tests\Feature\AuditConsole;

use App\Domain\Shared\Models\Team;
use App\Models\User;
use FleetQ\BorunaAudit\Enums\DecisionStatus;
use FleetQ\BorunaAudit\Models\AuditableDecision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_a_cannot_access_team_b_decision(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();

        $userA = User::factory()->create(['current_team_id' => $teamA->id]);
        $userA->teams()->attach($teamA);

        $decisionB = AuditableDecision::factory()->create([
            'team_id' => $teamB->id,
            'workflow_name' => 'driver_scoring',
            'status' => DecisionStatus::Completed,
        ]);

        $this->actingAs($userA)
            ->get(route('audit-console.show', $decisionB->id))
            ->assertNotFound();
    }
}
