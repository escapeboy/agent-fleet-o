<?php

namespace Tests\Feature\Domain\Website;

use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Actions\ExecuteCrewAction;
use App\Domain\Crew\Models\Crew;
use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Models\Team;
use App\Domain\Website\Actions\AssignWebsiteCrewAction;
use App\Domain\Website\Actions\ExecuteWebsiteCommandAction;
use App\Domain\Website\Models\Website;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class WebsiteManagementTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private Website $website;

    private User $user;

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

        $this->website = Website::create([
            'team_id' => $this->team->id,
            'name' => 'My Website',
            'slug' => 'my-website',
            'status' => 'draft',
            'settings' => [],
        ]);
    }

    private function makeCrew(Team $team): Crew
    {
        $coordinator = Agent::factory()->create(['team_id' => $team->id]);
        $qa = Agent::factory()->create(['team_id' => $team->id]);

        return Crew::factory()->create([
            'team_id' => $team->id,
            'coordinator_agent_id' => $coordinator->id,
            'qa_agent_id' => $qa->id,
        ]);
    }

    public function test_can_assign_crew_to_website(): void
    {
        $crew = $this->makeCrew($this->team);

        app(AssignWebsiteCrewAction::class)->execute($this->website, $crew->id);

        $this->assertSame($crew->id, $this->website->fresh()->managing_crew_id);
    }

    public function test_cannot_assign_crew_from_different_team(): void
    {
        $otherUser = User::factory()->create();
        $otherTeam = Team::create([
            'name' => 'Other Team',
            'slug' => 'other-team',
            'owner_id' => $otherUser->id,
            'settings' => [],
        ]);

        $crew = $this->makeCrew($otherTeam);

        $this->expectException(InvalidArgumentException::class);

        app(AssignWebsiteCrewAction::class)->execute($this->website, $crew->id);
    }

    public function test_can_unassign_crew(): void
    {
        $crew = $this->makeCrew($this->team);
        $this->website->update(['managing_crew_id' => $crew->id]);

        app(AssignWebsiteCrewAction::class)->execute($this->website, null);

        $this->assertNull($this->website->fresh()->managing_crew_id);
    }

    public function test_execute_command_without_crew_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(ExecuteWebsiteCommandAction::class)->execute($this->website, 'Update hero section');
    }

    public function test_can_execute_command_with_managing_crew(): void
    {
        $crew = $this->makeCrew($this->team);

        $this->website->update(['managing_crew_id' => $crew->id]);

        $capturedGoal = null;
        $fakeExecution = new CrewExecution([
            'id' => '00000000-0000-0000-0000-000000000001',
            'team_id' => $this->team->id,
            'crew_id' => $crew->id,
            'goal' => '',
            'status' => 'planning',
            'config_snapshot' => [],
            'coordinator_iterations' => 0,
            'total_cost_credits' => 0,
        ]);

        $mockAction = $this->createMock(ExecuteCrewAction::class);
        $mockAction->expects($this->once())
            ->method('execute')
            ->willReturnCallback(function (Crew $c, string $goal, string $teamId) use (&$capturedGoal, $fakeExecution) {
                $capturedGoal = $goal;

                return $fakeExecution;
            });

        $action = new ExecuteWebsiteCommandAction($mockAction);
        $action->execute($this->website->load('managingCrew'), 'Update the hero section', null);

        $this->assertStringContainsString('My Website', $capturedGoal);
        $this->assertStringContainsString('Update the hero section', $capturedGoal);
        $this->assertStringContainsString('my-website', $capturedGoal);
    }

    public function test_can_link_project_to_website(): void
    {
        $project = Project::factory()->create([
            'team_id' => $this->team->id,
            'website_id' => null,
        ]);

        $project->update(['website_id' => $this->website->id]);

        $this->assertSame($this->website->id, $project->fresh()->website_id);
    }
}
