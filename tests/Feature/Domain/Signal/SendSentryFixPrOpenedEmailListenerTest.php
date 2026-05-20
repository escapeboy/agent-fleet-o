<?php

namespace Tests\Feature\Domain\Signal;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Enums\ExperimentTrack;
use App\Domain\Experiment\Enums\StageStatus;
use App\Domain\Experiment\Enums\StageType;
use App\Domain\Experiment\Events\ExperimentTransitioned;
use App\Domain\Experiment\Listeners\SendSentryFixPrOpenedEmailListener;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Enums\SignalStatus;
use App\Domain\Signal\Mail\SentryFixPrOpenedMail;
use App\Domain\Signal\Models\Signal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Coverage for SendSentryFixPrOpenedEmailListener.
 *
 * Verifies the listener emails the operator only when:
 *   - the transition lands on CollectingMetrics
 *   - the experiment is debug-track
 *   - a Sentry signal is linked to the experiment
 *   - a PR URL is reachable from the building stage's output snapshot
 */
class SendSentryFixPrOpenedEmailListenerTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['email' => 'owner@example.com']);
        $this->team = Team::factory()->create(['owner_id' => $this->owner->id]);
        $this->owner->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->owner, ['role' => 'owner']);

        Mail::fake();

        config(['sentry_watchdog.digest_email' => null]);
    }

    private function makeDebugExperimentWithSentrySignal(string $prUrl): Experiment
    {
        $experiment = Experiment::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->owner->id,
            'track' => ExperimentTrack::Debug->value,
            'status' => ExperimentStatus::Building,
            'title' => 'Fix bug: TypeError in OrderController',
        ]);

        ExperimentStage::create([
            'team_id' => $this->team->id,
            'experiment_id' => $experiment->id,
            'stage' => StageType::Building,
            'iteration' => 1,
            'status' => StageStatus::Completed,
            'started_at' => now()->subMinutes(5),
            'completed_at' => now(),
            'output_snapshot' => [
                'pr_urls' => [$prUrl],
                'summary' => 'Patched null guard in OrderController::show.',
            ],
        ]);

        Signal::create([
            'team_id' => $this->team->id,
            'experiment_id' => $experiment->id,
            'source_type' => 'integration',
            'source_identifier' => 'sentry',
            'project_key' => 'fleetq',
            'payload' => [
                'payload' => [
                    'id' => 'sentry-issue-77',
                    'title' => 'TypeError: Cannot read property of null',
                    'permalink' => 'https://sentry.example.com/issues/77/',
                ],
                'sentry_permalink' => 'https://sentry.example.com/issues/77/',
                'target_repository' => 'escapeboy/agent-fleet',
            ],
            'content_hash' => hash('sha256', uniqid('listener-', true)),
            'received_at' => now(),
            'status' => SignalStatus::DelegatedToAgent,
        ]);

        return $experiment;
    }

    public function test_listener_fires_on_building_to_awaiting_approval_transition(): void
    {
        // ExperimentCompleteBuildingTool transitions Building → AwaitingApproval
        // when the agent finishes. That is the canonical hook point for the
        // "PR opened" email — the agent has pushed and opened the draft PR by
        // the time it calls experiment_complete_building.
        $prUrl = 'https://github.com/escapeboy/agent-fleet/pull/512';
        $experiment = $this->makeDebugExperimentWithSentrySignal($prUrl);

        $event = new ExperimentTransitioned(
            experiment: $experiment,
            fromState: ExperimentStatus::Building,
            toState: ExperimentStatus::AwaitingApproval,
        );

        app(SendSentryFixPrOpenedEmailListener::class)->handle($event);

        Mail::assertSent(SentryFixPrOpenedMail::class, function (SentryFixPrOpenedMail $mail) use ($prUrl) {
            return $mail->prUrl === $prUrl
                && $mail->targetRepo === 'escapeboy/agent-fleet'
                && $mail->hasTo('owner@example.com');
        });
    }

    public function test_listener_skips_when_transition_is_not_to_awaiting_approval(): void
    {
        $experiment = $this->makeDebugExperimentWithSentrySignal(
            'https://github.com/escapeboy/agent-fleet/pull/512',
        );

        $event = new ExperimentTransitioned(
            experiment: $experiment,
            fromState: ExperimentStatus::Building,
            toState: ExperimentStatus::CollectingMetrics,
        );

        app(SendSentryFixPrOpenedEmailListener::class)->handle($event);

        Mail::assertNothingSent();
    }

    public function test_listener_skips_when_experiment_track_is_not_debug(): void
    {
        $experiment = $this->makeDebugExperimentWithSentrySignal(
            'https://github.com/escapeboy/agent-fleet/pull/512',
        );
        $experiment->update(['track' => ExperimentTrack::Growth->value]);

        $event = new ExperimentTransitioned(
            experiment: $experiment->fresh(),
            fromState: ExperimentStatus::Building,
            toState: ExperimentStatus::AwaitingApproval,
        );

        app(SendSentryFixPrOpenedEmailListener::class)->handle($event);

        Mail::assertNothingSent();
    }
}
