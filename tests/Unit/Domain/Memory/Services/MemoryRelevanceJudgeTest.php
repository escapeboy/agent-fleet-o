<?php

namespace Tests\Unit\Domain\Memory\Services;

use App\Domain\Memory\Services\MemoryRelevanceJudge;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Services\ProviderResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class MemoryRelevanceJudgeTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $this->team = Team::factory()->create();
        config(['memory.deep_judgment.threshold' => 0.5]);
    }

    private function response(string $content): AiResponseDTO
    {
        return new AiResponseDTO(
            content: $content,
            parsedOutput: null,
            usage: new AiUsageDTO(promptTokens: 10, completionTokens: 5, costCredits: 0),
            provider: 'anthropic',
            model: 'claude-haiku-4-5',
            latencyMs: 20,
        );
    }

    private function resolver(): ProviderResolver
    {
        $resolver = Mockery::mock(ProviderResolver::class);
        $resolver->shouldReceive('resolve')->andReturn(['provider' => 'anthropic']);

        return $resolver;
    }

    private function candidates(): array
    {
        return [
            ['id' => 'mem-a', 'content' => 'Use feature flags for risky rollouts.'],
            ['id' => 'mem-b', 'content' => 'The office plant needs watering on Tuesdays.'],
        ];
    }

    public function test_keeps_only_above_threshold(): void
    {
        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')->once()
            ->andReturn($this->response('{"scores":[{"n":1,"score":0.9},{"n":2,"score":0.2}]}'));

        $judge = new MemoryRelevanceJudge($gateway, $this->resolver());

        $kept = $judge->judge('How should we ship risky changes?', $this->candidates(), $this->team->id, 'user-1');

        $this->assertSame(['mem-a'], $kept);
    }

    public function test_fails_open_when_gateway_throws(): void
    {
        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')->once()->andThrow(new \RuntimeException('boom'));

        $judge = new MemoryRelevanceJudge($gateway, $this->resolver());

        $kept = $judge->judge('q', $this->candidates(), $this->team->id, 'user-1');

        $this->assertSame(['mem-a', 'mem-b'], $kept);
    }

    public function test_fails_open_on_malformed_json(): void
    {
        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')->once()->andReturn($this->response('not json at all'));

        $judge = new MemoryRelevanceJudge($gateway, $this->resolver());

        $kept = $judge->judge('q', $this->candidates(), $this->team->id, 'user-1');

        $this->assertSame(['mem-a', 'mem-b'], $kept);
    }

    public function test_empty_candidates_skips_gateway(): void
    {
        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldNotReceive('complete');

        $judge = new MemoryRelevanceJudge($gateway, $this->resolver());

        $this->assertSame([], $judge->judge('q', [], $this->team->id, 'user-1'));
    }
}
