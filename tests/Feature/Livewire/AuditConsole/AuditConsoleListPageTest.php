<?php

namespace Tests\Feature\Livewire\AuditConsole;

use App\Domain\Shared\Models\Team;
use App\Livewire\AuditConsole\AuditConsoleListPage;
use App\Models\User;
use FleetQ\BorunaAudit\Enums\DecisionStatus;
use FleetQ\BorunaAudit\Models\AuditableDecision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AuditConsoleListPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_is_redirected(): void
    {
        $response = $this->get(route('audit-console.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_user_can_view_own_team_decisions(): void
    {
        $team = Team::factory()->create();
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $user->teams()->attach($team);

        AuditableDecision::factory()->create([
            'team_id' => $team->id,
            'workflow_name' => 'driver_scoring',
            'status' => DecisionStatus::Completed,
        ]);

        $this->actingAs($user);

        Livewire::test(AuditConsoleListPage::class)
            ->assertSee('driver_scoring');
    }

    public function test_user_cannot_see_other_team_decisions(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $userA = User::factory()->create(['current_team_id' => $teamA->id]);
        $userA->teams()->attach($teamA);

        AuditableDecision::factory()->create([
            'team_id' => $teamB->id,
            'workflow_name' => 'route_approval',
            'status' => DecisionStatus::Completed,
        ]);

        $this->actingAs($userA);

        Livewire::test(AuditConsoleListPage::class)
            ->assertSee('No audit decisions found');
    }

    public function test_workflow_filter_works(): void
    {
        $team = Team::factory()->create();
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $user->teams()->attach($team);

        AuditableDecision::factory()->create([
            'team_id' => $team->id,
            'workflow_name' => 'driver_scoring',
        ]);
        AuditableDecision::factory()->create([
            'team_id' => $team->id,
            'workflow_name' => 'route_approval',
        ]);

        $this->actingAs($user);

        Livewire::test(AuditConsoleListPage::class)
            ->set('workflow', 'driver_scoring')
            ->assertSeeHtml('data-workflow="driver_scoring"')
            ->assertDontSeeHtml('data-workflow="route_approval"');
    }

    public function test_status_filter_works(): void
    {
        $team = Team::factory()->create();
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $user->teams()->attach($team);

        AuditableDecision::factory()->create([
            'team_id' => $team->id,
            'workflow_name' => 'driver_scoring',
            'status' => DecisionStatus::Completed,
        ]);
        AuditableDecision::factory()->create([
            'team_id' => $team->id,
            'workflow_name' => 'route_approval',
            'status' => DecisionStatus::Failed,
        ]);

        $this->actingAs($user);

        Livewire::test(AuditConsoleListPage::class)
            ->set('status', 'completed')
            ->assertSeeHtml('data-workflow="driver_scoring"')
            ->assertDontSeeHtml('data-workflow="route_approval"');
    }
}
