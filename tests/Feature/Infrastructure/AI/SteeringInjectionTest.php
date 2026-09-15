<?php

namespace Tests\Feature\Infrastructure\AI;

use App\Domain\AgentSession\Actions\AppendSessionEventAction;
use App\Domain\AgentSession\Enums\AgentSessionEventKind;
use App\Domain\AgentSession\Enums\AgentSessionStatus;
use App\Domain\AgentSession\Models\AgentSession;
use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Experiment\Actions\SteerExperimentAction;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Middleware\SteeringInjection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SteeringInjectionTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Steer Middleware Test',
            'slug' => 'steer-mw-test',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
    }

    private function makeExperiment(string $title, array $config = []): Experiment
    {
        return Experiment::create([
            'team_id' => $this->team->id,
            'title' => $title,
            'status' => 'executing',
            'track' => 'growth',
            'description' => 't',
            'user_id' => $this->user->id,
            'initiated_by_user_id' => $this->user->id,
            'orchestration_config' => $config,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function queued(string $message, string $id, ?string $by = null): array
    {
        return ['id' => $id, 'message' => $message, 'queued_at' => '2026-04-19T10:00:00Z', 'queued_by' => $by];
    }

    private function makeRequest(?string $experimentId, string $systemPrompt = 'Base prompt.'): AiRequestDTO
    {
        return new AiRequestDTO(
            provider: 'anthropic',
            model: 'claude-sonnet-4-5',
            systemPrompt: $systemPrompt,
            userPrompt: 'Do the thing',
            teamId: $this->team->id,
            experimentId: $experimentId,
        );
    }

    private function dummyResponse(): AiResponseDTO
    {
        return new AiResponseDTO(
            content: 'ok',
            parsedOutput: null,
            usage: new AiUsageDTO(promptTokens: 1, completionTokens: 1, costCredits: 0),
            provider: 'test',
            model: 'test',
            latencyMs: 1,
        );
    }

    /**
     * Run the middleware and return the request that reached $next.
     */
    private function capture(AiRequestDTO $request): AiRequestDTO
    {
        $received = null;
        (new SteeringInjection)->handle($request, function (AiRequestDTO $r) use (&$received) {
            $received = $r;

            return $this->dummyResponse();
        });

        return $received;
    }

    public function test_no_op_when_experiment_id_is_null(): void
    {
        $this->assertSame('Base prompt.', $this->capture($this->makeRequest(null))->systemPrompt);
    }

    public function test_no_op_when_experiment_has_no_steering(): void
    {
        $experiment = $this->makeExperiment('No Steer');

        $this->assertSame('Base prompt.', $this->capture($this->makeRequest($experiment->id))->systemPrompt);
    }

    public function test_injects_single_queued_message_and_removes_it(): void
    {
        $experiment = $this->makeExperiment('With Steer', [
            'steering_queue' => [$this->queued('Use staging DB.', 'a')],
        ]);

        $received = $this->capture($this->makeRequest($experiment->id));

        $this->assertStringStartsWith('## STEERING', $received->systemPrompt);
        $this->assertStringContainsString('Use staging DB.', $received->systemPrompt);
        $this->assertStringContainsString('Base prompt.', $received->systemPrompt);

        $experiment->refresh();
        $this->assertArrayNotHasKey('steering_queue', $experiment->orchestration_config ?? []);
    }

    public function test_injects_all_queued_messages_in_order_as_one_block(): void
    {
        $experiment = $this->makeExperiment('Queue', [
            'steering_queue' => [$this->queued('first', 'a'), $this->queued('second', 'b')],
        ]);

        $received = $this->capture($this->makeRequest($experiment->id));

        $this->assertStringStartsWith("## STEERING (operator updates, apply in order)\n1. first\n2. second", $received->systemPrompt);
        $this->assertSame(1, substr_count($received->systemPrompt, '## STEERING'));

        $experiment->refresh();
        $this->assertArrayNotHasKey('steering_queue', $experiment->orchestration_config ?? []);
    }

    public function test_legacy_single_message_is_consumed_first_and_cleared(): void
    {
        $experiment = $this->makeExperiment('Legacy', [
            'steering_message' => 'old style',
            'steering_queued_at' => '2026-04-19T10:00:00Z',
            'steering_queue' => [$this->queued('new style', 'n')],
        ]);

        $received = $this->capture($this->makeRequest($experiment->id));

        $this->assertStringContainsString("1. old style\n2. new style", $received->systemPrompt);

        $experiment->refresh();
        $config = $experiment->orchestration_config ?? [];
        $this->assertArrayNotHasKey('steering_message', $config);
        $this->assertArrayNotHasKey('steering_queued_at', $config);
        $this->assertArrayNotHasKey('steering_queue', $config);
    }

    public function test_message_queued_during_llm_call_survives_the_clear(): void
    {
        $experiment = $this->makeExperiment('Race', [
            'steering_queue' => [$this->queued('before', 'a')],
        ]);

        (new SteeringInjection)->handle($this->makeRequest($experiment->id), function () use ($experiment) {
            app(SteerExperimentAction::class)->execute(experiment: $experiment->fresh(), message: 'during');

            return $this->dummyResponse();
        });

        $pending = SteerExperimentAction::pendingQueue($experiment->fresh()->orchestration_config ?? []);
        $this->assertSame(['during'], array_column($pending, 'message'));
    }

    public function test_entry_without_id_is_consumed_once_and_removed(): void
    {
        $experiment = $this->makeExperiment('No Id', [
            'steering_queue' => [['message' => 'hand edited', 'queued_at' => '2026-04-19T10:00:00Z']],
        ]);

        $first = $this->capture($this->makeRequest($experiment->id));
        $second = $this->capture($this->makeRequest($experiment->id));

        $this->assertStringContainsString('hand edited', $first->systemPrompt);
        $this->assertStringNotContainsString('STEERING', $second->systemPrompt);
        $this->assertArrayNotHasKey('steering_queue', $experiment->fresh()->orchestration_config ?? []);
    }

    public function test_session_mirror_failure_does_not_lose_the_response(): void
    {
        $experiment = $this->makeExperiment('Mirror Fails', [
            'steering_queue' => [$this->queued('keep going', 'a')],
        ]);
        AgentSession::create([
            'team_id' => $this->team->id,
            'experiment_id' => $experiment->id,
            'status' => AgentSessionStatus::Active,
        ]);
        $this->mock(AppendSessionEventAction::class, function ($mock) {
            $mock->shouldReceive('execute')->andThrow(new \RuntimeException('session store down'));
        });

        $response = (new SteeringInjection)->handle($this->makeRequest($experiment->id), fn () => $this->dummyResponse());

        $this->assertSame('ok', $response->content);
        $this->assertArrayNotHasKey('steering_queue', $experiment->fresh()->orchestration_config ?? []);
    }

    public function test_second_call_does_not_see_already_consumed_message(): void
    {
        $experiment = $this->makeExperiment('Consumed', [
            'steering_queue' => [$this->queued('One-shot', 'a')],
        ]);

        $this->capture($this->makeRequest($experiment->id));
        $received = $this->capture($this->makeRequest($experiment->id));

        $this->assertStringNotContainsString('STEERING', $received->systemPrompt);
    }

    public function test_writes_audit_entry_when_steering_consumed(): void
    {
        $experiment = $this->makeExperiment('Audit Trail', [
            'steering_queue' => [$this->queued('traced injection', 'a', $this->user->id)],
        ]);

        $this->capture($this->makeRequest($experiment->id));

        $entry = AuditEntry::where('event', 'experiment.steering_consumed')
            ->where('subject_id', $experiment->id)
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame($this->user->id, $entry->user_id);
        $this->assertSame(16, $entry->properties['message_length'] ?? null);
        $this->assertSame(1, $entry->properties['message_count'] ?? null);
        $this->assertSame(['a'], $entry->properties['steering_ids'] ?? null);
    }

    public function test_mirrors_applied_steering_into_agent_session(): void
    {
        $experiment = $this->makeExperiment('Session Mirror', [
            'steering_queue' => [$this->queued('one', 'a'), $this->queued('two', 'b')],
        ]);
        $session = AgentSession::create([
            'team_id' => $this->team->id,
            'experiment_id' => $experiment->id,
            'status' => AgentSessionStatus::Active,
        ]);

        $this->capture($this->makeRequest($experiment->id));

        $event = $session->events()->where('kind', AgentSessionEventKind::Steering->value)->first();
        $this->assertNotNull($event);
        $this->assertSame(2, $event->payload['message_count']);
        $this->assertSame(['a', 'b'], $event->payload['steering_ids']);
    }

    public function test_does_not_mirror_into_another_teams_session(): void
    {
        $experiment = $this->makeExperiment('Wrong Team', [
            'steering_queue' => [$this->queued('one', 'a')],
        ]);
        $otherTeam = Team::create(['name' => 'Other', 'slug' => 'other-steer', 'owner_id' => $this->user->id, 'settings' => []]);
        $foreign = AgentSession::create([
            'team_id' => $otherTeam->id,
            'experiment_id' => $experiment->id,
            'status' => AgentSessionStatus::Active,
        ]);

        $this->capture($this->makeRequest($experiment->id));

        $this->assertSame(0, $foreign->events()->count());
    }

    public function test_steering_stays_queued_when_next_throws(): void
    {
        $experiment = $this->makeExperiment('Retry Safe', [
            'steering_queue' => [$this->queued('do not lose me', 'a', $this->user->id)],
        ]);

        try {
            (new SteeringInjection)->handle($this->makeRequest($experiment->id), function () {
                throw new \RuntimeException('provider failed');
            });
            $this->fail('Expected RuntimeException to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('provider failed', $e->getMessage());
        }

        $experiment->refresh();
        $this->assertSame('do not lose me', $experiment->orchestration_config['steering_queue'][0]['message'] ?? null);
        $this->assertSame(0, AuditEntry::where('event', 'experiment.steering_consumed')->count());
    }

    public function test_preserves_fast_mode_flag_through_injection(): void
    {
        $experiment = $this->makeExperiment('Fast Mode Steering', [
            'steering_queue' => [$this->queued('hi', 'a')],
        ]);

        $request = new AiRequestDTO(
            provider: 'anthropic',
            model: 'claude-sonnet-4-5',
            systemPrompt: 'base',
            userPrompt: 'u',
            teamId: $this->team->id,
            experimentId: $experiment->id,
            fastMode: true,
        );

        $this->assertTrue($this->capture($request)->fastMode);
    }

    public function test_no_op_when_experiment_not_found(): void
    {
        $this->assertSame('Base prompt.', $this->capture($this->makeRequest('00000000-0000-0000-0000-000000000000'))->systemPrompt);
    }
}
