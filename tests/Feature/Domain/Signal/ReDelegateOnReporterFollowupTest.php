<?php

namespace Tests\Feature\Domain\Signal;

use App\Domain\Agent\Models\Agent;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Events\ExperimentTransitioned;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Signal\Actions\AddSignalCommentAction;
use App\Domain\Signal\Enums\CommentAuthorType;
use App\Domain\Signal\Enums\SignalStatus;
use App\Domain\Signal\Events\SignalCommentAdded;
use App\Domain\Signal\Listeners\ReDelegateOnReporterFollowupListener;
use App\Domain\Signal\Models\Signal;
use App\Domain\Signal\Models\SignalComment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Api\V1\ApiTestCase;

class ReDelegateOnReporterFollowupTest extends ApiTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        // Silence the post-delegation experiment cascade and the auto-firing
        // listener — we invoke the listener directly so we can assert on its
        // observable side effects without double-handling via the event dispatcher.
        Event::fake([ExperimentTransitioned::class, SignalCommentAdded::class]);
    }

    public function test_reporter_followup_in_review_re_delegates(): void
    {
        $signal = $this->seedSignalInReview();
        $previousExperimentId = $signal->experiment_id;
        $this->assertNotNull($previousExperimentId);

        $comment = app(AddSignalCommentAction::class)->execute(
            signal: $signal,
            body: 'This did not fix it — also do X.',
            authorType: CommentAuthorType::Reporter,
        );

        app(ReDelegateOnReporterFollowupListener::class)
            ->handle(new SignalCommentAdded($comment));

        $newSignalState = $signal->fresh();

        $this->assertNotSame(
            $previousExperimentId,
            $newSignalState->experiment_id,
            'Signal should be linked to a new experiment.',
        );

        $newExperiment = Experiment::find($newSignalState->experiment_id);
        $this->assertNotNull($newExperiment);
        $this->assertStringContainsString(
            'Reporter follow-up:',
            (string) $newExperiment->thesis,
        );
        $this->assertStringContainsString(
            'This did not fix it — also do X.',
            (string) $newExperiment->thesis,
        );
        $this->assertStringContainsString(
            'Previous agent attempt: experiment '.$previousExperimentId,
            (string) $newExperiment->thesis,
        );

        $this->assertDatabaseHas('signal_comments', [
            'signal_id' => $signal->id,
            'author_type' => 'agent',
            'idempotency_key' => 'reporter-followup:'.$comment->id,
        ]);
    }

    public function test_reporter_followup_on_resolved_signal_does_nothing(): void
    {
        $signal = $this->seedSignalInReview();
        $signal->update(['status' => SignalStatus::Resolved]);
        $previousExperimentId = $signal->experiment_id;

        $comment = app(AddSignalCommentAction::class)->execute(
            signal: $signal,
            body: 'late follow-up',
            authorType: CommentAuthorType::Reporter,
        );

        $beforeExperimentCount = Experiment::count();

        app(ReDelegateOnReporterFollowupListener::class)
            ->handle(new SignalCommentAdded($comment));

        $this->assertSame($beforeExperimentCount, Experiment::count(), 'No new experiment should be created.');
        $this->assertSame($previousExperimentId, $signal->fresh()->experiment_id);
    }

    public function test_agent_comment_does_not_trigger_re_delegation(): void
    {
        $signal = $this->seedSignalInReview();
        $beforeExperimentCount = Experiment::count();

        $comment = app(AddSignalCommentAction::class)->execute(
            signal: $signal,
            body: 'PR #22 opened.',
            authorType: CommentAuthorType::Agent,
        );

        app(ReDelegateOnReporterFollowupListener::class)
            ->handle(new SignalCommentAdded($comment));

        $this->assertSame($beforeExperimentCount, Experiment::count());
    }

    public function test_idempotency_re_running_listener_does_not_double_post_system_comment(): void
    {
        $signal = $this->seedSignalInReview();

        $comment = app(AddSignalCommentAction::class)->execute(
            signal: $signal,
            body: 'still broken',
            authorType: CommentAuthorType::Reporter,
        );

        $listener = app(ReDelegateOnReporterFollowupListener::class);
        $listener->handle(new SignalCommentAdded($comment));
        $listener->handle(new SignalCommentAdded($comment));

        $systemComments = SignalComment::query()
            ->where('signal_id', $signal->id)
            ->where('idempotency_key', 'reporter-followup:'.$comment->id)
            ->count();

        $this->assertSame(1, $systemComments, 'Idempotency must keep the system comment count at 1.');
    }

    public function test_signal_without_previous_experiment_is_skipped(): void
    {
        $signal = $this->seedSignalInReview();
        $signal->update(['experiment_id' => null]);

        $comment = app(AddSignalCommentAction::class)->execute(
            signal: $signal,
            body: 'still broken',
            authorType: CommentAuthorType::Reporter,
        );

        $beforeExperimentCount = Experiment::count();

        app(ReDelegateOnReporterFollowupListener::class)
            ->handle(new SignalCommentAdded($comment));

        $this->assertSame($beforeExperimentCount, Experiment::count());
    }

    private function seedSignalInReview(): Signal
    {
        $agent = Agent::factory()->for($this->team)->create([
            'provider' => 'anthropic',
            'model' => 'claude-haiku-4-5',
        ]);

        $previousExperiment = Experiment::create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'agent_id' => $agent->id,
            'title' => 'Fix bug: original',
            'thesis' => 'original thesis',
            'track' => 'debug',
            'status' => ExperimentStatus::Completed,
        ]);

        $signal = Signal::create([
            'team_id' => $this->team->id,
            'experiment_id' => $previousExperiment->id,
            'source_type' => 'bug_report',
            'source_identifier' => 'lukanet/collector2',
            'project_key' => 'lukanet/collector2',
            'status' => SignalStatus::Review,
            'content_hash' => md5('br-'.bin2hex(random_bytes(6))),
            'received_at' => now(),
            'payload' => [
                'title' => 'Submit broken',
                'description' => 'edit form blank',
                'severity' => 'major',
                'url' => 'https://app.example.com/edit',
                'reporter_id' => 'r-1',
                'reporter_name' => 'Alice',
                'action_log' => [],
                'console_log' => [],
                'network_log' => [],
                'browser' => 'Mozilla/5.0',
                'viewport' => '1440x900',
                'environment' => 'production',
            ],
            'tags' => ['bug_report'],
        ]);

        return $signal;
    }
}
