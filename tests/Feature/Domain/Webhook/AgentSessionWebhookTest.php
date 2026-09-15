<?php

namespace Tests\Feature\Domain\Webhook;

use App\Domain\AgentSession\Actions\CancelAgentSessionAction;
use App\Domain\AgentSession\Actions\SleepAgentSessionAction;
use App\Domain\AgentSession\Enums\AgentSessionStatus;
use App\Domain\AgentSession\Listeners\CloseAgentSessionOnTerminal;
use App\Domain\AgentSession\Models\AgentSession;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Enums\ExperimentTrack;
use App\Domain\Experiment\Events\ExperimentTransitioned;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Models\Team;
use App\Domain\Webhook\Listeners\SendWebhookOnAgentSessionNeedsInput;
use App\Domain\Webhook\Models\WebhookEndpoint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spatie\WebhookServer\CallWebhookJob;
use Tests\TestCase;

/**
 * agent.session.completed / failed / cancelled fire on every path that ends a
 * session, including MCP-started sessions with no experiment.
 * agent.session.needs_input fires when the session's experiment stops for a human.
 */
class AgentSessionWebhookTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->team = $this->makeTeam();
    }

    private function makeTeam(): Team
    {
        $user = User::factory()->create();

        return Team::create([
            'name' => 'T',
            'slug' => 't-'.Str::lower(Str::random(8)),
            'owner_id' => $user->id,
            'settings' => [],
        ]);
    }

    /**
     * @param  array<int, string>  $events
     */
    private function endpoint(array $events, ?Team $team = null, string $url = 'https://partner.test/hook'): WebhookEndpoint
    {
        return WebhookEndpoint::create([
            'team_id' => ($team ?? $this->team)->id,
            'name' => 'Partner',
            'url' => $url,
            'secret' => 'shhh',
            'events' => $events,
            'is_active' => true,
            'retry_config' => ['max_retries' => 3],
        ]);
    }

    private function makeSession(AgentSessionStatus $status = AgentSessionStatus::Active, ?string $experimentId = null): AgentSession
    {
        return AgentSession::create([
            'team_id' => $this->team->id,
            'experiment_id' => $experimentId,
            'status' => $status,
            'started_at' => now(),
        ]);
    }

    private function experiment(): Experiment
    {
        return Experiment::factory()->create([
            'team_id' => $this->team->id,
            'track' => ExperimentTrack::Workflow,
            'status' => ExperimentStatus::Executing,
            'constraints' => [],
            'title' => 'x',
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sent(): array
    {
        return Queue::pushed(CallWebhookJob::class)
            ->map(fn (CallWebhookJob $job): array => ['url' => $job->webhookUrl, ...$job->payload])
            ->values()
            ->all();
    }

    public function test_cancelling_a_session_without_an_experiment_sends_cancelled(): void
    {
        $this->endpoint(['agent.session.cancelled']);
        $session = $this->makeSession();

        app(CancelAgentSessionAction::class)->execute($session, 'operator stop');

        $sent = $this->sent();
        $this->assertCount(1, $sent);
        $this->assertSame('agent.session.cancelled', $sent[0]['event']);
        $this->assertSame($session->id, $sent[0]['data']['id']);
        $this->assertSame('cancelled', $sent[0]['data']['status']);
        $this->assertSame('active', $sent[0]['data']['previous_status']);
        $this->assertNull($sent[0]['data']['experiment_id']);
        $this->assertNotNull($sent[0]['data']['ended_at']);
    }

    public function test_experiment_completion_sends_session_completed(): void
    {
        $this->endpoint(['agent.session.completed']);
        $experiment = $this->experiment();
        $session = $this->makeSession(experimentId: $experiment->id);

        app(CloseAgentSessionOnTerminal::class)->handle(
            new ExperimentTransitioned($experiment, ExperimentStatus::Executing, ExperimentStatus::Completed),
        );

        $sent = $this->sent();
        $this->assertCount(1, $sent);
        $this->assertSame('agent.session.completed', $sent[0]['event']);
        $this->assertSame($session->id, $sent[0]['data']['id']);
        $this->assertSame($experiment->id, $sent[0]['data']['experiment_id']);
    }

    public function test_failed_status_sends_failed(): void
    {
        $this->endpoint(['*']);

        $this->makeSession()->update(['status' => AgentSessionStatus::Failed, 'ended_at' => now()]);

        $sent = $this->sent();
        $this->assertCount(1, $sent);
        $this->assertSame('agent.session.failed', $sent[0]['event']);
    }

    public function test_non_terminal_status_change_sends_nothing(): void
    {
        $this->endpoint(['*']);

        app(SleepAgentSessionAction::class)->execute($this->makeSession(), 'sandbox restart');

        Queue::assertNotPushed(CallWebhookJob::class);
    }

    public function test_updating_an_already_ended_session_sends_nothing(): void
    {
        $this->endpoint(['*']);
        $session = $this->makeSession(AgentSessionStatus::Completed);

        $session->update(['status' => AgentSessionStatus::Failed]);
        $session->update(['metadata' => ['note' => 'late']]);

        Queue::assertNotPushed(CallWebhookJob::class);
    }

    public function test_only_subscribed_endpoints_of_the_session_team_receive_it(): void
    {
        $this->endpoint(['*'], $this->makeTeam(), 'https://other-team.test/hook');
        $this->endpoint(['experiment.completed'], url: 'https://unsubscribed.test/hook');
        $this->endpoint(['agent.session.cancelled'], url: 'https://subscribed.test/hook');

        app(CancelAgentSessionAction::class)->execute($this->makeSession());

        $sent = $this->sent();
        $this->assertCount(1, $sent);
        $this->assertSame('https://subscribed.test/hook', $sent[0]['url']);
    }

    public function test_awaiting_approval_with_an_open_session_sends_needs_input(): void
    {
        $this->endpoint(['agent.session.needs_input']);
        $experiment = $this->experiment();
        $session = $this->makeSession(experimentId: $experiment->id);

        app(SendWebhookOnAgentSessionNeedsInput::class)->handle(
            new ExperimentTransitioned($experiment, ExperimentStatus::Planning, ExperimentStatus::AwaitingApproval),
        );

        $sent = $this->sent();
        $this->assertCount(1, $sent);
        $this->assertSame('agent.session.needs_input', $sent[0]['event']);
        $this->assertSame($session->id, $sent[0]['data']['id']);
        $this->assertSame('experiment_awaiting_approval', $sent[0]['data']['reason']);
        $this->assertSame('awaiting_approval', $sent[0]['data']['experiment_status']);
        $this->assertSame('planning', $sent[0]['data']['previous_experiment_status']);
    }

    public function test_needs_input_skips_closed_sessions_and_other_transitions(): void
    {
        $this->endpoint(['*']);
        $experiment = $this->experiment();
        $this->makeSession(AgentSessionStatus::Completed, $experiment->id);

        app(SendWebhookOnAgentSessionNeedsInput::class)->handle(
            new ExperimentTransitioned($experiment, ExperimentStatus::Planning, ExperimentStatus::AwaitingApproval),
        );

        $open = $this->experiment();
        $this->makeSession(experimentId: $open->id);
        app(SendWebhookOnAgentSessionNeedsInput::class)->handle(
            new ExperimentTransitioned($open, ExperimentStatus::Building, ExperimentStatus::Executing),
        );

        Queue::assertNotPushed(CallWebhookJob::class);
    }
}
