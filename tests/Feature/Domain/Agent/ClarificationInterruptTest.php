<?php

namespace Tests\Feature\Domain\Agent;

use App\Domain\Approval\Actions\CompleteHumanTaskAction;
use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Budget\Enums\LedgerType;
use App\Domain\Budget\Models\CreditLedger;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Pipeline\ExecutePlaybookStepJob;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ClarificationInterruptTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    private Experiment $experiment;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);

        CreditLedger::withoutGlobalScopes()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'type' => LedgerType::Purchase,
            'amount' => 100000,
            'balance_after' => 100000,
            'description' => 'Test balance',
        ]);

        $this->experiment = Experiment::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'status' => ExperimentStatus::AwaitingApproval,
        ]);
    }

    public function test_approval_request_isClarification_returns_true_when_type_is_clarification(): void
    {
        $approval = ApprovalRequest::create([
            'team_id' => $this->team->id,
            'experiment_id' => $this->experiment->id,
            'status' => ApprovalStatus::Pending,
            'context' => [
                'type' => 'clarification',
                'question' => 'What target audience should I focus on?',
                'step_id' => 'test-step-id',
                'original_input' => ['task' => 'Write a blog post'],
            ],
            'form_schema' => [
                'fields' => [
                    ['name' => 'answer', 'label' => 'What target audience should I focus on?', 'type' => 'textarea', 'required' => true],
                ],
            ],
        ]);

        $this->assertTrue($approval->isClarification());
        $this->assertTrue($approval->isHumanTask()); // isHumanTask should also be true
    }

    public function test_approval_request_isClarification_returns_false_for_standard_approvals(): void
    {
        $approval = ApprovalRequest::create([
            'team_id' => $this->team->id,
            'experiment_id' => $this->experiment->id,
            'status' => ApprovalStatus::Pending,
            'context' => ['description' => 'Outbound approval'],
        ]);

        $this->assertFalse($approval->isClarification());
    }

    public function test_complete_human_task_action_redispatches_job_for_clarification(): void
    {
        $stepId = 'step-uuid-abc';
        $originalInput = ['task' => 'Write a blog post about AI'];

        $approval = ApprovalRequest::create([
            'team_id' => $this->team->id,
            'experiment_id' => $this->experiment->id,
            'status' => ApprovalStatus::Pending,
            'context' => [
                'type' => 'clarification',
                'question' => 'What audience should I write for?',
                'step_id' => $stepId,
                'original_input' => $originalInput,
            ],
            'form_schema' => [
                'fields' => [
                    ['name' => 'answer', 'label' => 'What audience should I write for?', 'type' => 'textarea', 'required' => true],
                ],
            ],
        ]);

        app(CompleteHumanTaskAction::class)->execute(
            approvalRequest: $approval,
            formResponse: ['answer' => 'Software engineers with 5+ years experience'],
            reviewerId: $this->user->id,
        );

        // Approval should be marked as approved with form_response stored
        $approval->refresh();
        $this->assertEquals(ApprovalStatus::Approved, $approval->status);
        $this->assertEquals('Software engineers with 5+ years experience', $approval->form_response['answer']);

        // Should have dispatched ExecutePlaybookStepJob with clarification_answer in inputOverrides
        Queue::assertPushed(ExecutePlaybookStepJob::class, function (ExecutePlaybookStepJob $job) use ($stepId, $originalInput) {
            return $job->stepId === $stepId
                && $job->experimentId === $this->experiment->id
                && isset($job->inputOverrides['clarification_answer'])
                && $job->inputOverrides['clarification_answer'] === 'Software engineers with 5+ years experience'
                && $job->inputOverrides['task'] === $originalInput['task'];
        });
    }

    public function test_experiment_transitions_to_executing_after_clarification_completed(): void
    {
        $stepId = 'step-uuid-xyz';

        $approval = ApprovalRequest::create([
            'team_id' => $this->team->id,
            'experiment_id' => $this->experiment->id,
            'status' => ApprovalStatus::Pending,
            'context' => [
                'type' => 'clarification',
                'question' => 'Should this be formal or casual tone?',
                'step_id' => $stepId,
                'original_input' => ['task' => 'Draft email'],
            ],
            'form_schema' => [
                'fields' => [
                    ['name' => 'answer', 'label' => 'Tone?', 'type' => 'textarea', 'required' => true],
                ],
            ],
        ]);

        app(CompleteHumanTaskAction::class)->execute(
            approvalRequest: $approval,
            formResponse: ['answer' => 'Formal, professional tone'],
            reviewerId: $this->user->id,
        );

        $this->experiment->refresh();
        $this->assertEquals(ExperimentStatus::Executing, $this->experiment->status);
    }
}
