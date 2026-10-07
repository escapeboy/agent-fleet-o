<?php

namespace Tests\Feature\Domain\Project;

use App\Domain\Agent\Models\Agent;
use App\Domain\Budget\Enums\LedgerType;
use App\Domain\Budget\Models\CreditLedger;
use App\Domain\Credential\Models\Credential;
use App\Domain\Crew\Models\Crew;
use App\Domain\Project\Enums\ProjectHealthState;
use App\Domain\Project\Enums\ProjectRunStatus;
use App\Domain\Project\Enums\ProjectStatus;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectRun;
use App\Domain\Project\Models\ProjectSchedule;
use App\Domain\Project\Services\ProjectHealthEvaluator;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectHealthEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $this->team = Team::factory()->create();
    }

    private function project(array $attrs = []): Project
    {
        return Project::factory()->for($this->team)->create(array_merge(['status' => ProjectStatus::Active], $attrs));
    }

    private function makeRun(Project $project, ProjectRunStatus $status, int $number, array $attrs = []): ProjectRun
    {
        return ProjectRun::create(array_merge([
            'project_id' => $project->id,
            'run_number' => $number,
            'status' => $status,
            'trigger' => 'manual',
            'input_data' => [],
            'spend_credits' => 0,
        ], $attrs));
    }

    private function codes(Project $project): array
    {
        return array_map(fn ($r) => $r->code, app(ProjectHealthEvaluator::class)->evaluate($project)->reasons);
    }

    public function test_draft_and_archived_are_off(): void
    {
        foreach ([ProjectStatus::Draft, ProjectStatus::Archived] as $status) {
            $report = app(ProjectHealthEvaluator::class)->evaluate($this->project(['status' => $status]));
            $this->assertSame(ProjectHealthState::Off, $report->state);
            $this->assertCount(1, $report->reasons);
            $this->assertSame('project_inactive', $report->reasons[0]->code);
        }
    }

    public function test_manual_pause_is_off_and_budget_pause_is_stopped(): void
    {
        $manual = $this->project(['status' => ProjectStatus::Paused]);
        activity()->performedOn($manual)->withProperties(['reason' => 'Manually paused'])->log('project.paused');
        $report = app(ProjectHealthEvaluator::class)->evaluate($manual);
        $this->assertSame(ProjectHealthState::Off, $report->state);
        $this->assertSame('paused_manual', $report->reasons[0]->code);

        $budget = $this->project(['status' => ProjectStatus::Paused]);
        activity()->performedOn($budget)->withProperties(['reason' => 'Budget cap exceeded (daily)'])->log('project.paused');
        $report = app(ProjectHealthEvaluator::class)->evaluate($budget);
        $this->assertSame(ProjectHealthState::Stopped, $report->state);
        $this->assertContains('paused_budget', $this->codes($budget));
    }

    public function test_latest_pause_reason_wins(): void
    {
        $project = $this->project(['status' => ProjectStatus::Paused]);
        activity()->performedOn($project)->withProperties(['reason' => 'Budget cap exceeded (daily)'])->log('project.paused');
        activity()->performedOn($project)->withProperties(['reason' => 'Manually paused'])->log('project.paused');

        $this->assertSame(['paused_manual'], $this->codes($project));
    }

    public function test_failed_project_is_stopped_and_message_has_truncated_last_error(): void
    {
        $project = $this->project(['status' => ProjectStatus::Failed]);
        $this->makeRun($project, ProjectRunStatus::Failed, 1, ['error_message' => str_repeat('x', 500)]);

        $report = app(ProjectHealthEvaluator::class)->evaluate($project);
        $this->assertSame(ProjectHealthState::Stopped, $report->state);
        $reason = $report->reasons[0];
        $this->assertSame('failed_consecutive', $reason->code);
        $this->assertStringContainsString('1 consecutive', $reason->message);
        $this->assertStringContainsString(str_repeat('x', 200), $reason->message);
        $this->assertStringNotContainsString(str_repeat('x', 201), $reason->message);
    }

    public function test_team_out_of_credits(): void
    {
        $project = $this->project();
        $this->assertNotContains('team_out_of_credits', $this->codes($project));

        CreditLedger::create([
            'team_id' => $this->team->id,
            'user_id' => User::factory()->create()->id,
            'type' => LedgerType::Purchase->value,
            'amount' => 100,
            'balance_after' => 0,
            'description' => 'seed',
        ]);

        $this->assertContains('team_out_of_credits', $this->codes($project));
    }

    public function test_schedule_disabled_is_degraded(): void
    {
        $project = $this->project();
        ProjectSchedule::create(['project_id' => $project->id, 'enabled' => false]);

        $report = app(ProjectHealthEvaluator::class)->evaluate($project->fresh());
        $this->assertSame(ProjectHealthState::Degraded, $report->state);
        $this->assertSame('schedule_disabled', $report->reasons[0]->code);
    }

    public function test_schedule_overdue_boundaries(): void
    {
        $project = $this->project();
        $schedule = ProjectSchedule::create(['project_id' => $project->id, 'enabled' => true, 'next_run_at' => now()->subHour()]);
        $this->assertContains('schedule_overdue', $this->codes($project->fresh()));

        $schedule->update(['next_run_at' => now()->subMinutes(5)]);
        $this->assertSame([], $this->codes($project->fresh()));
    }

    public function test_stuck_run_boundaries(): void
    {
        $project = $this->project();
        $this->makeRun($project, ProjectRunStatus::Running, 1, ['started_at' => now()->subHours(2)]);
        $this->assertContains('run_stuck', $this->codes($project));

        ProjectRun::query()->where('project_id', $project->id)->delete();
        $this->makeRun($project, ProjectRunStatus::Running, 2, ['started_at' => now()->subMinutes(5)]);
        $this->assertNotContains('run_stuck', $this->codes($project));
    }

    public function test_recent_failure_while_active_is_degraded(): void
    {
        $project = $this->project();
        $this->makeRun($project, ProjectRunStatus::Completed, 1);
        $this->makeRun($project, ProjectRunStatus::Failed, 2);
        $this->makeRun($project, ProjectRunStatus::Completed, 3);

        $report = app(ProjectHealthEvaluator::class)->evaluate($project);
        $this->assertSame(ProjectHealthState::Degraded, $report->state);
        $this->assertSame(['recent_failures'], $this->codes($project));
    }

    public function test_agent_unavailable_degraded_then_stopped(): void
    {
        $lead = Agent::factory()->for($this->team)->create();
        $project = $this->project(['agent_config' => ['lead_agent_id' => $lead->id]]);
        $this->assertSame([], $this->codes($project));

        $lead->update(['status' => 'disabled']);
        $report = app(ProjectHealthEvaluator::class)->evaluate($project);
        $this->assertSame(ProjectHealthState::Stopped, $report->state, 'sole agent disabled = all agents unavailable');
        $this->assertSame('agent_unavailable', $report->reasons[0]->code);
    }

    public function test_agent_unavailable_is_degraded_when_only_some_agents_are_bad(): void
    {
        $good = Agent::factory()->for($this->team)->create();
        $bad = Agent::factory()->for($this->team)->disabled()->create();
        $crew = Crew::factory()->for($this->team)->create(['coordinator_agent_id' => $bad->id, 'qa_agent_id' => $good->id]);
        $project = $this->project(['agent_config' => ['lead_agent_id' => $good->id]]);
        $project->update(['crew_id' => $crew->id]);

        $report = app(ProjectHealthEvaluator::class)->evaluate($project->fresh());
        $this->assertSame(ProjectHealthState::Degraded, $report->state);
        $this->assertSame('agent_unavailable', $report->reasons[0]->code);
    }

    public function test_credential_expired_and_expiring(): void
    {
        $expired = Credential::factory()->expired()->create(['team_id' => $this->team->id, 'name' => 'Old key']);
        $expiring = Credential::factory()->create(['team_id' => $this->team->id, 'name' => 'Soon key', 'expires_at' => now()->addDays(3)]);

        $project = $this->project(['allowed_credential_ids' => [$expired->id]]);
        $this->assertSame(['credential_unusable'], $this->codes($project));

        $project->update(['allowed_credential_ids' => [$expiring->id]]);
        $report = app(ProjectHealthEvaluator::class)->evaluate($project->fresh());
        $this->assertSame(['credential_expiring'], array_map(fn ($r) => $r->code, $report->reasons));
        $this->assertSame(ProjectHealthState::Degraded, $report->state);
    }

    public function test_healthy_active_project_is_ok(): void
    {
        $report = app(ProjectHealthEvaluator::class)->evaluate($this->project());

        $this->assertSame(ProjectHealthState::Ok, $report->state);
        $this->assertSame([], $report->reasons);
        $this->assertSame('ok', $report->toArray()['state']);
    }

    public function test_worst_severity_wins_and_keeps_all_reasons(): void
    {
        $project = $this->project(['status' => ProjectStatus::Failed]);
        $this->makeRun($project, ProjectRunStatus::Failed, 1);
        $this->makeRun($project, ProjectRunStatus::Running, 2, ['started_at' => now()->subHours(3)]);

        $report = app(ProjectHealthEvaluator::class)->evaluate($project);
        $this->assertSame(ProjectHealthState::Stopped, $report->state);
        $this->assertEqualsCanonicalizing(['failed_consecutive', 'run_stuck'], $this->codes($project));
    }

    public function test_other_team_data_does_not_leak(): void
    {
        $other = Team::factory()->create();
        $otherProject = Project::factory()->for($other)->create(['status' => ProjectStatus::Failed]);
        $this->makeRun($otherProject, ProjectRunStatus::Failed, 1);
        $otherAgent = Agent::factory()->for($other)->disabled()->create();

        $project = $this->project(['agent_config' => ['lead_agent_id' => $otherAgent->id]]);

        $this->assertSame(ProjectHealthState::Ok, app(ProjectHealthEvaluator::class)->evaluate($project)->state);
    }
}
