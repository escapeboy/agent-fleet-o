<?php

namespace Tests\Feature\Domain\Approval;

use App\Domain\Approval\Actions\CreateApprovalRequestAction;
use App\Domain\Approval\Jobs\SummarizeApprovalJob;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CreateApprovalRequestSummaryTest extends TestCase
{
    use RefreshDatabase;

    private function experimentForTeam(Team $team): Experiment
    {
        return Experiment::factory()->create(['team_id' => $team->id]);
    }

    public function test_summary_job_not_dispatched_when_flag_off(): void
    {
        Queue::fake();
        $team = Team::factory()->create(['settings' => []]);
        $experiment = $this->experimentForTeam($team);

        $approval = app(CreateApprovalRequestAction::class)->execute($experiment);

        Queue::assertNotPushed(SummarizeApprovalJob::class);
        $this->assertEquals(1, $approval->required_approvals);
    }

    public function test_summary_job_dispatched_when_flag_on(): void
    {
        Queue::fake();
        $team = Team::factory()->create(['settings' => ['approval_ai_summary' => true]]);
        $experiment = $this->experimentForTeam($team);

        app(CreateApprovalRequestAction::class)->execute($experiment);

        Queue::assertPushed(SummarizeApprovalJob::class);
    }

    public function test_required_approvals_is_persisted(): void
    {
        Queue::fake();
        $team = Team::factory()->create(['settings' => []]);
        $experiment = $this->experimentForTeam($team);

        $approval = app(CreateApprovalRequestAction::class)->execute($experiment, requiredApprovals: 3);

        $this->assertEquals(3, $approval->fresh()->required_approvals);
    }
}
