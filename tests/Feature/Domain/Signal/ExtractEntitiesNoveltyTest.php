<?php

namespace Tests\Feature\Domain\Signal;

use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Actions\ExtractEntitiesAction;
use App\Domain\Signal\Enums\SignalStatus;
use App\Domain\Signal\Models\Signal;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ExtractEntitiesNoveltyTest extends TestCase
{
    use RefreshDatabase;

    private function fakeGateway(string $content): void
    {
        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')->andReturn(new AiResponseDTO(
            content: $content,
            parsedOutput: null,
            usage: new AiUsageDTO(promptTokens: 100, completionTokens: 50, costCredits: 5),
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            latencyMs: 100,
        ));
        $this->app->instance(AiGatewayInterface::class, $gateway);
    }

    private function makeSignal(Team $team): Signal
    {
        return Signal::create([
            'team_id' => $team->id,
            'source_type' => 'hacker_news',
            'source_identifier' => 'hacker_news:top',
            'status' => SignalStatus::Received,
            'content_hash' => md5('novelty-'.uniqid()),
            'received_at' => now(),
            'payload' => [
                'title' => 'Unprecedented breakthrough announced',
                'body' => 'A surprising new architecture was announced today with major implications for the field.',
            ],
            'tags' => ['hacker_news'],
        ]);
    }

    public function test_persists_novelty_from_enrichment_call(): void
    {
        $team = Team::factory()->create();
        $this->fakeGateway(json_encode([
            'entities' => [
                ['type' => 'topic', 'name' => 'architecture', 'context_sentence' => 'new architecture', 'confidence' => 0.9],
            ],
            'novelty' => 4,
        ]));

        $signal = $this->makeSignal($team);
        $entities = app(ExtractEntitiesAction::class)->execute($signal);

        $signal->refresh();
        $this->assertSame(4, $signal->metadata['novelty']);
        $this->assertNotEmpty($entities);
    }

    public function test_clamps_out_of_range_novelty(): void
    {
        $team = Team::factory()->create();
        $this->fakeGateway(json_encode([
            'entities' => [],
            'novelty' => 9,
        ]));

        $signal = $this->makeSignal($team);
        app(ExtractEntitiesAction::class)->execute($signal);

        $signal->refresh();
        $this->assertSame(5, $signal->metadata['novelty']);
    }

    public function test_legacy_array_response_sets_no_novelty(): void
    {
        $team = Team::factory()->create();
        $this->fakeGateway(json_encode([
            ['type' => 'company', 'name' => 'Acme', 'context_sentence' => 'Acme did', 'confidence' => 0.8],
        ]));

        $signal = $this->makeSignal($team);
        $entities = app(ExtractEntitiesAction::class)->execute($signal);

        $signal->refresh();
        $this->assertArrayNotHasKey('novelty', $signal->metadata ?? []);
        $this->assertNotEmpty($entities);
    }
}
