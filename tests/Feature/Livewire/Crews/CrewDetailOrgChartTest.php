<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Crews;

use App\Domain\Agent\Models\Agent;
use App\Domain\AgentChatProtocol\Models\ExternalAgent;
use App\Domain\Crew\Enums\CrewExecutionStatus;
use App\Domain\Crew\Enums\CrewMemberRole;
use App\Domain\Crew\Enums\CrewStatus;
use App\Domain\Crew\Enums\CrewTaskStatus;
use App\Domain\Crew\Models\Crew;
use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Crew\Models\CrewMember;
use App\Domain\Crew\Models\CrewTaskExecution;
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

    public function test_it_marks_active_agents_during_running_execution(): void
    {
        $crew = $this->makeCrew(workerCount: 2);
        /** @var CrewMember $worker */
        $worker = $crew->workerMembers()->first();

        $execution = CrewExecution::create([
            'team_id' => $this->team->id,
            'crew_id' => $crew->id,
            'user_id' => $this->user->id,
            'status' => CrewExecutionStatus::Executing,
            'goal' => 'Test goal',
        ]);

        CrewTaskExecution::create([
            'team_id' => $this->team->id,
            'crew_execution_id' => $execution->id,
            'agent_id' => $worker->agent_id,
            'title' => 'Running task',
            'description' => 'Test',
            'status' => CrewTaskStatus::Running,
            'sort_order' => 0,
        ]);

        Livewire::test(CrewDetailPage::class, ['crew' => $crew])
            ->assertSeeHtml('animate-pulse')
            ->assertSeeHtml('wire:poll.5s');
    }

    public function test_it_does_not_pulse_when_no_running_execution(): void
    {
        $crew = $this->makeCrew(workerCount: 1);

        Livewire::test(CrewDetailPage::class, ['crew' => $crew])
            ->assertDontSeeHtml('animate-pulse')
            ->assertDontSeeHtml('wire:poll.5s');
    }

    private function makeExecution(Crew $crew, string $goal, int $total, string $createdAt): CrewExecution
    {
        $execution = CrewExecution::create([
            'team_id' => $this->team->id,
            'crew_id' => $crew->id,
            'user_id' => $this->user->id,
            'status' => CrewExecutionStatus::Completed,
            'goal' => $goal,
            'total_cost_credits' => $total,
            'config_snapshot' => [],
        ]);
        $execution->forceFill(['created_at' => $createdAt])->save();

        return $execution;
    }

    private function makeTask(CrewExecution $execution, string $agentId, string $title, int $cost, int $sortOrder): CrewTaskExecution
    {
        return CrewTaskExecution::create([
            'crew_execution_id' => $execution->id,
            'agent_id' => $agentId,
            'title' => $title,
            'description' => 'Test',
            'status' => CrewTaskStatus::Validated,
            'cost_credits' => $cost,
            'sort_order' => $sortOrder,
        ]);
    }

    public function test_it_shows_latest_execution_goal_on_org_chart(): void
    {
        $crew = $this->makeCrew(workerCount: 1);
        $this->makeExecution($crew, 'Old goal', 10, '2026-01-01 10:00:00');
        $this->makeExecution($crew, 'Launch pricing page', 20, '2026-02-01 10:00:00');

        Livewire::test(CrewDetailPage::class, ['crew' => $crew])
            ->assertSeeHtml('data-test="org-chart-goal"')
            ->assertSee('Launch pricing page')
            ->assertDontSee('Old goal')
            ->assertDontSeeHtml('data-test="org-chart-no-runs"');
    }

    public function test_it_shows_task_and_spend_per_worker_branch(): void
    {
        $crew = $this->makeCrew(workerCount: 2);
        $workerIds = $crew->workerMembers()->orderBy('sort_order')->pluck('agent_id')->all();
        $execution = $this->makeExecution($crew, 'Launch pricing page', 100, '2026-02-01 10:00:00');
        $this->makeTask($execution, $workerIds[0], 'Draft copy', 30, 0);
        $this->makeTask($execution, $workerIds[0], 'Polish copy', 12, 1);

        Livewire::test(CrewDetailPage::class, ['crew' => $crew])
            ->assertSeeHtml('data-test="org-chart-worker-task"')
            ->assertSee('Polish copy')
            ->assertSeeHtmlInOrder([
                'data-test="org-chart-worker-cost">42 credits<',
                'data-test="org-chart-worker-cost">0 credits<',
            ]);
    }

    public function test_it_prefers_running_task_over_later_pending_task_for_worker_branch(): void
    {
        $crew = $this->makeCrew(workerCount: 1);
        $workerId = (string) $crew->workerMembers()->value('agent_id');
        $execution = $this->makeExecution($crew, 'Launch pricing page', 10, '2026-02-01 10:00:00');
        $this->makeTask($execution, $workerId, 'Running draft', 3, 0)
            ->update(['status' => CrewTaskStatus::Running]);
        $this->makeTask($execution, $workerId, 'Queued polish', 0, 1)
            ->update(['status' => CrewTaskStatus::Pending]);

        Livewire::test(CrewDetailPage::class, ['crew' => $crew])
            ->assertSee('Running draft')
            ->assertDontSee('Queued polish');
    }

    public function test_coordinator_self_assigned_task_counts_as_coordination_cost(): void
    {
        $crew = $this->makeCrew(workerCount: 1);
        $workerId = (string) $crew->workerMembers()->value('agent_id');
        $execution = $this->makeExecution($crew, 'Launch pricing page', 100, '2026-02-01 10:00:00');
        $this->makeTask($execution, (string) $crew->coordinator_agent_id, 'Coordinator research', 25, 0);
        $this->makeTask($execution, $workerId, 'Draft copy', 30, 1);

        Livewire::test(CrewDetailPage::class, ['crew' => $crew])
            ->assertSeeHtml('data-test="org-chart-worker-cost">30 credits<')
            ->assertSeeHtml('Coordination + QA: 70 credits');
    }

    public function test_it_ignores_tasks_from_older_executions_in_branch_spend(): void
    {
        $crew = $this->makeCrew(workerCount: 1);
        $workerId = (string) $crew->workerMembers()->value('agent_id');
        $old = $this->makeExecution($crew, 'Old goal', 600, '2026-01-01 10:00:00');
        $this->makeTask($old, $workerId, 'Old task', 500, 0);
        $latest = $this->makeExecution($crew, 'Launch pricing page', 50, '2026-02-01 10:00:00');
        $this->makeTask($latest, $workerId, 'New task', 7, 0);

        Livewire::test(CrewDetailPage::class, ['crew' => $crew])
            ->assertSeeHtml('data-test="org-chart-worker-cost">7 credits<')
            ->assertDontSee('500 credits')
            ->assertDontSee('507 credits')
            ->assertDontSee('Old task');
    }

    public function test_it_shows_run_total_and_coordination_cost_on_coordinator(): void
    {
        $crew = $this->makeCrew(workerCount: 2);
        $workerIds = $crew->workerMembers()->orderBy('sort_order')->pluck('agent_id')->all();
        $execution = $this->makeExecution($crew, 'Launch pricing page', 100, '2026-02-01 10:00:00');
        $this->makeTask($execution, $workerIds[0], 'Draft copy', 30, 0);
        $this->makeTask($execution, $workerIds[1], 'Review copy', 12, 1);

        Livewire::test(CrewDetailPage::class, ['crew' => $crew])
            ->assertSeeHtmlInOrder(['data-test="org-chart-total-cost"', 'Run total: 100 credits'])
            ->assertSeeHtmlInOrder(['data-test="org-chart-coordination-cost"', 'Coordination + QA: 58 credits']);
    }

    public function test_it_shows_no_goal_block_when_crew_has_no_executions(): void
    {
        $crew = $this->makeCrew(workerCount: 1);

        Livewire::test(CrewDetailPage::class, ['crew' => $crew])
            ->assertSeeHtml('data-test="org-chart-no-runs"')
            ->assertDontSeeHtml('data-test="org-chart-goal"')
            ->assertDontSeeHtml('data-test="org-chart-worker-cost"')
            ->assertDontSeeHtml('data-test="org-chart-total-cost"');
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
