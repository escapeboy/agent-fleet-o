<?php

namespace Tests\Feature\Infrastructure\AI;

use App\Domain\Audit\Models\AuditEntry;
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

    public function test_no_op_when_experiment_id_is_null(): void
    {
        $middleware = new SteeringInjection;
        $request = $this->makeRequest(null);

        $received = null;
        $middleware->handle($request, function (AiRequestDTO $r) use (&$received) {
            $received = $r;

            return $this->dummyResponse();
        });

        $this->assertSame('Base prompt.', $received->systemPrompt);
    }

    public function test_no_op_when_experiment_has_no_steering_message(): void
    {
        $experiment = Experiment::create([
            'team_id' => $this->team->id,
            'title' => 'No Steer',
            'status' => 'executing',
            'track' => 'growth',
            'description' => 't',
            'user_id' => $this->user->id,
            'initiated_by_user_id' => $this->user->id,
        ]);

        $middleware = new SteeringInjection;
        $request = $this->makeRequest($experiment->id);

        $received = null;
        $middleware->handle($request, function (AiRequestDTO $r) use (&$received) {
            $received = $r;

            return $this->dummyResponse();
        });

        $this->assertSame('Base prompt.', $received->systemPrompt);
    }

    public function test_injects_pending_message_and_clears_it(): void
    {
        $experiment = Experiment::create([
            'team_id' => $this->team->id,
            'title' => 'With Steer',
            'status' => 'executing',
            'track' => 'growth',
            'description' => 't',
            'user_id' => $this->user->id,
            'initiated_by_user_id' => $this->user->id,
            'orchestration_config' => [
                'steering_message' => 'Use staging DB.',
                'steering_queued_at' => '2026-04-19T10:00:00Z',
            ],
        ]);

        $middleware = new SteeringInjection;
        $request = $this->makeRequest($experiment->id);

        $received = null;
        $middleware->handle($request, function (AiRequestDTO $r) use (&$received) {
            $received = $r;

            return $this->dummyResponse();
        });

        // Steering block prepended
        $this->assertStringContainsString('STEERING', $received->systemPrompt);
        $this->assertStringContainsString('Use staging DB.', $received->systemPrompt);
        $this->assertStringContainsString('Base prompt.', $received->systemPrompt);
        $this->assertStringStartsWith('## STEERING', $received->systemPrompt);

        // Message cleared from experiment config
        $experiment->refresh();
        $this->assertArrayNotHasKey('steering_message', $experiment->orchestration_config ?? []);
        $this->assertArrayNotHasKey('steering_queued_at', $experiment->orchestration_config ?? []);
    }

    public function test_second_call_does_not_see_already_consumed_message(): void
    {
        $experiment = Experiment::create([
            'team_id' => $this->team->id,
            'title' => 'Consumed',
            'status' => 'executing',
            'track' => 'growth',
            'description' => 't',
            'user_id' => $this->user->id,
            'initiated_by_user_id' => $this->user->id,
            'orchestration_config' => ['steering_message' => 'One-shot'],
        ]);

        $middleware = new SteeringInjection;
        $next = fn ($r) => $this->dummyResponse();

        // First call: consumes the message
        $middleware->handle($this->makeRequest($experiment->id), $next);

        // Second call: nothing to inject
        $received = null;
        $middleware->handle($this->makeRequest($experiment->id), function (AiRequestDTO $r) use (&$received) {
            $received = $r;

            return $this->dummyResponse();
        });

        $this->assertStringNotContainsString('STEERING', $received->systemPrompt);
    }

    public function test_writes_audit_entry_when_steering_consumed(): void
    {
        $experiment = Experiment::create([
            'team_id' => $this->team->id,
            'title' => 'Audit Trail',
            'status' => 'executing',
            'track' => 'growth',
            'description' => 't',
            'user_id' => $this->user->id,
            'initiated_by_user_id' => $this->user->id,
            'orchestration_config' => [
                'steering_message' => 'traced injection',
                'steering_queued_by' => $this->user->id,
            ],
        ]);

        $middleware = new SteeringInjection;
        $middleware->handle($this->makeRequest($experiment->id), fn ($r) => $this->dummyResponse());

        $entry = AuditEntry::where('event', 'experiment.steering_consumed')
            ->where('subject_id', $experiment->id)
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame($this->user->id, $entry->user_id);
        $this->assertSame(16, $entry->properties['message_length'] ?? null);
    }

    public function test_no_op_when_experiment_not_found(): void
    {
        $middleware = new SteeringInjection;
        $request = $this->makeRequest('00000000-0000-0000-0000-000000000000');

        $received = null;
        $middleware->handle($request, function (AiRequestDTO $r) use (&$received) {
            $received = $r;

            return $this->dummyResponse();
        });

        $this->assertSame('Base prompt.', $received->systemPrompt);
    }
}
