<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Crews;

use App\Domain\Agent\Models\Agent;
use App\Domain\AgentChatProtocol\Models\ExternalAgent;
use App\Domain\Crew\Enums\CrewMemberRole;
use App\Domain\Crew\Enums\CrewStatus;
use App\Domain\Crew\Models\Crew;
use App\Domain\Crew\Models\CrewMember;
use App\Domain\Shared\Models\Team;
use App\Livewire\Crews\CrewDetailPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CrewDetailOrgChartTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Org Chart Test Team',
            'slug' => 'org-chart-test-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);
    }

    private function makeCrew(int $workerCount = 2): Crew
    {
        $coordinator = Agent::factory()->for($this->team)->create(['name' => 'Test Coordinator']);
        $qa = Agent::factory()->for($this->team)->create(['name' => 'Test QA']);

        $crew = Crew::factory()->for($this->team)->create([
            'user_id' => $this->user->id,
            'coordinator_agent_id' => $coordinator->id,
            'qa_agent_id' => $qa->id,
            'status' => CrewStatus::Active,
        ]);

        for ($i = 0; $i < $workerCount; $i++) {
            $worker = Agent::factory()->for($this->team)->create(['name' => "Test Worker {$i}"]);
            CrewMember::factory()->create([
                'crew_id' => $crew->id,
                'agent_id' => $worker->id,
                'role' => CrewMemberRole::Worker,
                'sort_order' => $i,
            ]);
        }

        return $crew->fresh();
    }

    public function test_it_renders_org_chart_view_by_default(): void
    {
        $crew = $this->makeCrew(workerCount: 2);

        Livewire::test(CrewDetailPage::class, ['crew' => $crew])
            ->assertSet('orgChartView', 'chart')
            ->assertSeeHtml('data-test="crew-org-chart"')
            ->assertSeeHtml('data-test="org-chart-coordinator"')
            ->assertSeeHtml('data-test="org-chart-qa"')
            ->assertSeeHtmlInOrder([
                'data-test="org-chart-worker"',
                'data-test="org-chart-worker"',
            ])
            ->assertSee('Test Coordinator')
            ->assertSee('Test QA')
            ->assertSee('Test Worker 0')
            ->assertSee('Test Worker 1');
    }

    public function test_it_renders_org_chart_with_only_qa_when_no_workers(): void
    {
        $crew = $this->makeCrew(workerCount: 0);

        Livewire::test(CrewDetailPage::class, ['crew' => $crew])
            ->assertSeeHtml('data-test="org-chart-coordinator"')
            ->assertSeeHtml('data-test="org-chart-qa"')
            ->assertDontSeeHtml('data-test="org-chart-worker"')
            ->assertSeeHtml('data-test-bottom-count="1"');
    }

    public function test_it_marks_external_members_with_badge(): void
    {
        $crew = $this->makeCrew(workerCount: 0);

        $external = ExternalAgent::create([
            'team_id' => $this->team->id,
            'name' => 'External Specialist',
            'slug' => 'external-specialist',
            'endpoint_url' => 'https://example.com/agent',
        ]);

        CrewMember::create([
            'crew_id' => $crew->id,
            'agent_id' => null,
            'external_agent_id' => $external->id,
            'member_kind' => 'external',
            'role' => CrewMemberRole::Worker,
            'sort_order' => 0,
            'config' => [],
        ]);

        Livewire::test(CrewDetailPage::class, ['crew' => $crew])
            ->assertSee('external');
    }

    public function test_it_toggles_between_chart_and_list_views(): void
    {
        $crew = $this->makeCrew(workerCount: 1);

        Livewire::test(CrewDetailPage::class, ['crew' => $crew])
            ->assertSet('orgChartView', 'chart')
            ->assertSeeHtml('data-test="crew-org-chart"')
            ->set('orgChartView', 'list')
            ->assertDontSeeHtml('data-test="crew-org-chart"')
            ->assertSee('Coordinator')
            ->assertSee('QA Agent')
            ->set('orgChartView', 'chart')
            ->assertSeeHtml('data-test="crew-org-chart"');
    }
}
