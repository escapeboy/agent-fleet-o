<?php

namespace Tests\Feature\Domain\Signal;

use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Actions\ClassifySignalIntentAction;
use App\Domain\Signal\Enums\SignalInferredIntent;
use App\Domain\Signal\Events\SignalIngested;
use App\Domain\Signal\Jobs\ClassifySignalIntentJob;
use App\Domain\Signal\Listeners\InferIncomingSignalIntent;
use App\Domain\Signal\Models\Signal;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class ClassifySignalIntentTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Signal Team',
            'slug' => 'signal-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
    }

    public function test_classification_writes_intent_to_metadata(): void
    {
        $signal = Signal::factory()->create([
            'team_id' => $this->team->id,
            'source_type' => 'webhook',
            'payload' => ['subject' => 'Contract signed 🎉', 'body' => 'Thanks, deal closed.'],
        ]);

        $this->bindGateway('action_completed', 'Deal closed in message body');

        $intent = app(ClassifySignalIntentAction::class)->execute($signal);

        $this->assertSame(SignalInferredIntent::ActionCompleted, $intent);
        $fresh = $signal->fresh();
        $this->assertSame('action_completed', $fresh->metadata['inferred_intent']);
        $this->assertArrayHasKey('inferred_intent_classifier', $fresh->metadata);
        $this->assertNotEmpty($fresh->metadata['inferred_intent_at']);
    }

    public function test_listener_skips_bug_report_source(): void
    {
        Queue::fake();
        $signal = Signal::factory()->create([
            'team_id' => $this->team->id,
            'source_type' => 'bug_report',
            'payload' => ['severity' => 'critical'],
        ]);

        (new InferIncomingSignalIntent)->handle(new SignalIngested($signal));

        Queue::assertNothingPushed();
    }

    public function test_listener_queues_classification_job_for_webhook_signals(): void
    {
        Queue::fake();
        $signal = Signal::factory()->create([
            'team_id' => $this->team->id,
            'source_type' => 'webhook',
            'payload' => ['x' => 'y'],
        ]);

        (new InferIncomingSignalIntent)->handle(new SignalIngested($signal));

        Queue::assertPushed(ClassifySignalIntentJob::class);
    }

    public function test_job_skips_already_classified_signal(): void
    {
        $signal = Signal::factory()->create([
            'team_id' => $this->team->id,
            'source_type' => 'webhook',
            'payload' => ['x' => 'y'],
            'metadata' => ['inferred_intent' => 'neutral'],
        ]);

        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldNotReceive('complete');
        $this->app->instance(AiGatewayInterface::class, $gateway);

        (new ClassifySignalIntentJob($signal->id))->handle(app(ClassifySignalIntentAction::class));

        $this->assertSame('neutral', $signal->fresh()->metadata['inferred_intent']);
    }

    public function test_returns_null_when_llm_response_unparsable(): void
    {
        $signal = Signal::factory()->create([
            'team_id' => $this->team->id,
            'source_type' => 'webhook',
            'payload' => ['x' => 'y'],
        ]);

        $this->bindGateway('garbage intent name', 'whatever');

        $intent = app(ClassifySignalIntentAction::class)->execute($signal);

        $this->assertNull($intent);
    }

    private function bindGateway(string $intent, string $reasoning): void
    {
        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')->andReturn(new AiResponseDTO(
            content: json_encode(['intent' => $intent, 'reasoning' => $reasoning]),
            parsedOutput: ['intent' => $intent, 'reasoning' => $reasoning],
            usage: new AiUsageDTO(50, 30, 0.02),
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            latencyMs: 42,
            schemaValid: true,
            cached: false,
        ));
        $this->app->instance(AiGatewayInterface::class, $gateway);
    }
}
