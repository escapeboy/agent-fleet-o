<?php

namespace Tests\Unit\Domain\Memory\Actions;

use App\Domain\Agent\Models\Agent;
use App\Domain\Memory\Actions\DetectMemoryContradictionsAction;
use App\Domain\Memory\Models\Memory;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Services\ProviderResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DetectMemoryContradictionsActionTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->team = Team::factory()->create();
        $this->agent = Agent::factory()->create(['team_id' => $this->team->id]);
    }

    private function makeResponse(string $content): AiResponseDTO
    {
        return new AiResponseDTO(
            content: $content,
            parsedOutput: null,
            usage: new AiUsageDTO(promptTokens: 10, completionTokens: 5, costCredits: 0),
            provider: 'anthropic',
            model: 'claude-haiku-4-5',
            latencyMs: 40,
        );
    }

    private function providerResolver(): ProviderResolver
    {
        $resolver = Mockery::mock(ProviderResolver::class);
        $resolver->shouldReceive('resolve')->andReturn(['provider' => 'anthropic']);

        return $resolver;
    }

    /**
     * @return array{0: Memory, 1: Memory}
     */
    private function pair(): array
    {
        return [
            Memory::create([
                'team_id' => $this->team->id, 'agent_id' => $this->agent->id,
                'content' => 'Deploy on Fridays', 'source_type' => 'test',
            ]),
            Memory::create([
                'team_id' => $this->team->id, 'agent_id' => $this->agent->id,
                'content' => 'Never deploy on Fridays', 'source_type' => 'test',
            ]),
        ];
    }

    public function test_scan_pairs_flags_a_contradicting_pair(): void
    {
        [$a, $b] = $this->pair();

        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')->once()
            ->andReturn($this->makeResponse('{"contradictions": [1]}'));

        $action = new DetectMemoryContradictionsAction($gateway, $this->providerResolver());
        $flagged = $action->scanPairs([[$a, $b]], $this->team->id);

        $this->assertSame(1, $flagged);
        $this->assertTrue($a->fresh()->conflict_flag);
        $this->assertTrue($b->fresh()->conflict_flag);
        $this->assertSame($b->id, $a->fresh()->conflict_with_id);
        $this->assertSame($a->id, $b->fresh()->conflict_with_id);
    }

    public function test_scan_pairs_leaves_non_contradicting_pair_untouched(): void
    {
        [$a, $b] = $this->pair();

        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')->once()
            ->andReturn($this->makeResponse('{"contradictions": []}'));

        $action = new DetectMemoryContradictionsAction($gateway, $this->providerResolver());
        $flagged = $action->scanPairs([[$a, $b]], $this->team->id);

        $this->assertSame(0, $flagged);
        $this->assertFalse($a->fresh()->conflict_flag);
        $this->assertFalse($b->fresh()->conflict_flag);
    }

    public function test_scan_pairs_returns_zero_when_gateway_throws(): void
    {
        [$a, $b] = $this->pair();

        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')->once()->andThrow(new \RuntimeException('LLM down'));

        $action = new DetectMemoryContradictionsAction($gateway, $this->providerResolver());

        $this->assertSame(0, $action->scanPairs([[$a, $b]], $this->team->id));
        $this->assertFalse($a->fresh()->conflict_flag);
    }

    public function test_scan_pairs_with_no_pairs_skips_the_llm_call(): void
    {
        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldNotReceive('complete');

        $action = new DetectMemoryContradictionsAction($gateway, $this->providerResolver());

        $this->assertSame(0, $action->scanPairs([], $this->team->id));
    }
}
