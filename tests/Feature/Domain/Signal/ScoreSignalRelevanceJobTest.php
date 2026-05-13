<?php

namespace Tests\Feature\Domain\Signal;

use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Jobs\ScoreSignalRelevanceJob;
use App\Domain\Signal\Models\Signal;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Services\ProviderResolver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ScoreSignalRelevanceJobTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team',
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($user, ['role' => 'owner']);
    }

    private function makeSignal(array $payload, ?float $existingScore = null): Signal
    {
        return Signal::withoutGlobalScopes()->create([
            'team_id' => $this->team->id,
            'source_type' => 'webhook',
            'source_identifier' => 'webhook-test',
            'content_hash' => md5(json_encode($payload).uniqid()),
            'payload' => $payload,
            'received_at' => now(),
            'relevance_score' => $existingScore,
        ]);
    }

    private function runJob(Signal $signal, AiGatewayInterface $gateway): void
    {
        (new ScoreSignalRelevanceJob($signal->id))->handle(
            $gateway,
            app(ProviderResolver::class),
        );
    }

    public function test_scores_signal_from_ai_response(): void
    {
        $signal = $this->makeSignal(['event' => 'order.placed', 'amount' => 500]);

        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')->once()->andReturn(
            new AiResponseDTO(
                content: '{"score": 0.85, "reason": "High-value order event, actionable."}',
                parsedOutput: null,
                usage: new AiUsageDTO(50, 20, 1),
                provider: 'anthropic',
                model: 'claude-haiku-4-5',
                latencyMs: 100,
            ),
        );

        $this->runJob($signal, $gateway);

        $signal->refresh();
        $this->assertEqualsWithDelta(0.85, $signal->relevance_score, 0.001);
        $this->assertNotNull($signal->relevance_scored_at);
    }

    public function test_skips_already_scored_signal(): void
    {
        $signal = $this->makeSignal(['event' => 'ping'], existingScore: 0.5);

        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldNotReceive('complete');

        $this->runJob($signal, $gateway);

        $signal->refresh();
        $this->assertEqualsWithDelta(0.5, $signal->relevance_score, 0.001);
    }

    public function test_handles_gateway_failure_gracefully(): void
    {
        $signal = $this->makeSignal(['event' => 'test']);

        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')->once()->andThrow(new \RuntimeException('Provider down'));

        $this->runJob($signal, $gateway);

        $signal->refresh();
        $this->assertNull($signal->relevance_score);
    }

    public function test_clamps_score_to_valid_range(): void
    {
        $signal = $this->makeSignal(['event' => 'test']);

        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')->once()->andReturn(
            new AiResponseDTO(
                content: '{"score": 1.5, "reason": "Excellent signal"}',
                parsedOutput: null,
                usage: new AiUsageDTO(50, 20, 1),
                provider: 'anthropic',
                model: 'claude-haiku-4-5',
                latencyMs: 100,
            ),
        );

        $this->runJob($signal, $gateway);

        $signal->refresh();
        $this->assertEqualsWithDelta(1.0, $signal->relevance_score, 0.001);
    }
}
