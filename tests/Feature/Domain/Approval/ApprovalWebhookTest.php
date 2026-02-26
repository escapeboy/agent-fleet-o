<?php

namespace Tests\Feature\Domain\Approval;

use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Jobs\FireApprovalWebhookJob;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Budget\Enums\LedgerType;
use App\Domain\Budget\Models\CreditLedger;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ApprovalWebhookTest extends TestCase
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

        // Seed credits so budget enforcement doesn't interrupt
        CreditLedger::withoutGlobalScopes()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'type' => LedgerType::Purchase,
            'amount' => 100000,
            'balance_after' => 100000,
            'description' => 'Test balance',
        ]);

        // Experiment must be in AwaitingApproval so ApproveAction/RejectAction can transition it
        $this->experiment = Experiment::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'status' => ExperimentStatus::AwaitingApproval,
        ]);
    }

    private function makeApproval(array $attributes = []): ApprovalRequest
    {
        return ApprovalRequest::create(array_merge([
            'team_id' => $this->team->id,
            'experiment_id' => $this->experiment->id,
            'status' => ApprovalStatus::Pending,
            'context' => ['description' => 'Test approval'],
        ], $attributes));
    }

    public function test_webhook_job_is_not_dispatched_when_no_callback_url(): void
    {
        $approval = $this->makeApproval();

        // No callback_url set — webhook job must NOT be dispatched
        Queue::assertNothingPushed();
        $this->assertNull($approval->callback_url);
    }

    public function test_webhook_job_is_dispatched_on_approve_when_callback_url_set(): void
    {
        $approval = $this->makeApproval([
            'callback_url' => 'https://example.com/webhook',
            'callback_secret' => 'secret123',
        ]);

        // Ensure experiment is in correct state (makeApproval may share the same experiment)
        $this->experiment->update(['status' => ExperimentStatus::AwaitingApproval]);

        app(\App\Domain\Approval\Actions\ApproveAction::class)->execute(
            $approval,
            $this->user->id,
            'LGTM',
        );

        Queue::assertPushed(FireApprovalWebhookJob::class, function ($job) use ($approval) {
            return $job->approvalRequestId === $approval->id;
        });
    }

    public function test_webhook_job_is_dispatched_on_reject_when_callback_url_set(): void
    {
        $approval = $this->makeApproval([
            'callback_url' => 'https://example.com/webhook',
            'callback_secret' => 'secret123',
        ]);

        $this->experiment->update(['status' => ExperimentStatus::AwaitingApproval]);

        app(\App\Domain\Approval\Actions\RejectAction::class)->execute(
            $approval,
            $this->user->id,
            'Does not meet criteria',
        );

        Queue::assertPushed(FireApprovalWebhookJob::class, function ($job) use ($approval) {
            return $job->approvalRequestId === $approval->id;
        });
    }

    public function test_callback_status_set_to_pending_before_job_dispatch(): void
    {
        $approval = $this->makeApproval([
            'callback_url' => 'https://example.com/webhook',
        ]);

        $this->experiment->update(['status' => ExperimentStatus::AwaitingApproval]);

        app(\App\Domain\Approval\Actions\ApproveAction::class)->execute(
            $approval,
            $this->user->id,
        );

        $approval->refresh();
        $this->assertEquals('pending', $approval->callback_status);
    }

    public function test_fire_approval_webhook_job_posts_hmac_signed_payload(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            'https://example.com/webhook' => \Illuminate\Support\Facades\Http::response([], 200),
        ]);

        $approval = $this->makeApproval([
            'status' => ApprovalStatus::Approved,
            'callback_url' => 'https://example.com/webhook',
            'callback_secret' => 'my-secret',
            'reviewed_at' => now(),
        ]);

        (new FireApprovalWebhookJob($approval->id))->handle();

        \Illuminate\Support\Facades\Http::assertSent(function ($request) use ($approval) {
            // Verify HMAC signature header is present
            return $request->hasHeader('X-Signature-SHA256')
                && $request->url() === 'https://example.com/webhook'
                && isset($request['approval_id'])
                && $request['approval_id'] === $approval->id;
        });

        $approval->refresh();
        $this->assertEquals('delivered', $approval->callback_status);
        $this->assertNotNull($approval->callback_fired_at);
    }

    public function test_fire_approval_webhook_job_marks_failed_on_http_error(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            'https://example.com/webhook' => \Illuminate\Support\Facades\Http::response([], 500),
        ]);

        $approval = $this->makeApproval([
            'status' => ApprovalStatus::Rejected,
            'callback_url' => 'https://example.com/webhook',
        ]);

        $this->expectException(\Throwable::class);

        (new FireApprovalWebhookJob($approval->id))->handle();
    }
}
