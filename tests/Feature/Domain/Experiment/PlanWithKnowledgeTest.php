<?php

namespace Tests\Feature\Domain\Experiment;

use App\Domain\Experiment\Actions\PlanWithKnowledgeAction;
use App\Domain\KnowledgeGraph\Actions\SearchKgFactsAction;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Services\ProviderResolver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlanWithKnowledgeTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team-'.Str::random(6),
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
    }

    public function test_returns_expected_structure(): void
    {
        $mockGateway = $this->createMock(AiGatewayInterface::class);
        $mockGateway->method('complete')->willReturn(new AiResponseDTO(
            content: '{"insights": ["Think carefully about dependencies"], "risks": ["Timeline may slip"], "key_questions": ["Who owns the outcome?"]}',
            parsedOutput: null,
            usage: new AiUsageDTO(promptTokens: 100, completionTokens: 50, costCredits: 5),
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            latencyMs: 100,
        ));

        $mockResolver = $this->createMock(ProviderResolver::class);
        $mockResolver->method('resolve')->willReturn(['provider' => 'anthropic', 'model' => 'claude-haiku-4-5-20251001']);

        $mockKgSearch = $this->createMock(SearchKgFactsAction::class);
        $mockKgSearch->method('execute')->willReturn(collect());

        $action = new PlanWithKnowledgeAction($mockGateway, $mockResolver, $mockKgSearch);

        $result = $action->execute('Build a recommendation engine', $this->team->id);

        $this->assertArrayHasKey('memory_hits', $result);
        $this->assertArrayHasKey('kg_hits', $result);
        $this->assertArrayHasKey('first_principles', $result);
        $this->assertArrayHasKey('enriched_context', $result);

        $this->assertIsArray($result['memory_hits']);
        $this->assertIsArray($result['kg_hits']);
        $this->assertArrayHasKey('insights', $result['first_principles']);
        $this->assertArrayHasKey('risks', $result['first_principles']);
        $this->assertArrayHasKey('key_questions', $result['first_principles']);
        $this->assertIsString($result['enriched_context']);
    }

    public function test_handles_empty_memory_and_kg_gracefully(): void
    {
        $mockGateway = $this->createMock(AiGatewayInterface::class);
        $mockGateway->method('complete')->willReturn(new AiResponseDTO(
            content: '{"insights": [], "risks": [], "key_questions": []}',
            parsedOutput: null,
            usage: new AiUsageDTO(promptTokens: 50, completionTokens: 10, costCredits: 2),
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            latencyMs: 100,
        ));

        $mockResolver = $this->createMock(ProviderResolver::class);
        $mockResolver->method('resolve')->willReturn(['provider' => 'anthropic', 'model' => 'claude-haiku-4-5-20251001']);

        $mockKgSearch = $this->createMock(SearchKgFactsAction::class);
        $mockKgSearch->method('execute')->willReturn(collect());

        $action = new PlanWithKnowledgeAction($mockGateway, $mockResolver, $mockKgSearch);

        $result = $action->execute('Do something with no prior context', $this->team->id);

        $this->assertSame([], $result['memory_hits']);
        $this->assertSame([], $result['kg_hits']);
        $this->assertSame([], $result['first_principles']['insights']);
        $this->assertSame([], $result['first_principles']['risks']);
        $this->assertSame([], $result['first_principles']['key_questions']);
        $this->assertSame('', $result['enriched_context']);
    }

    public function test_kg_exception_is_caught_and_returns_empty_hits(): void
    {
        $mockGateway = $this->createMock(AiGatewayInterface::class);
        $mockGateway->method('complete')->willReturn(new AiResponseDTO(
            content: '{"insights": ["Carry on"], "risks": [], "key_questions": []}',
            parsedOutput: null,
            usage: new AiUsageDTO(promptTokens: 50, completionTokens: 10, costCredits: 2),
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            latencyMs: 100,
        ));

        $mockResolver = $this->createMock(ProviderResolver::class);
        $mockResolver->method('resolve')->willReturn(['provider' => 'anthropic', 'model' => 'claude-haiku-4-5-20251001']);

        $mockKgSearch = $this->createMock(SearchKgFactsAction::class);
        $mockKgSearch->method('execute')->willThrowException(new \RuntimeException('KG not configured'));

        $action = new PlanWithKnowledgeAction($mockGateway, $mockResolver, $mockKgSearch);

        $result = $action->execute('Launch new product line', $this->team->id);

        $this->assertSame([], $result['kg_hits']);
        $this->assertNotEmpty($result['first_principles']['insights']);
    }

    public function test_llm_exception_returns_empty_first_principles(): void
    {
        $mockGateway = $this->createMock(AiGatewayInterface::class);
        $mockGateway->method('complete')->willThrowException(new \RuntimeException('LLM unavailable'));

        $mockResolver = $this->createMock(ProviderResolver::class);
        $mockResolver->method('resolve')->willReturn(['provider' => 'anthropic', 'model' => 'claude-haiku-4-5-20251001']);

        $mockKgSearch = $this->createMock(SearchKgFactsAction::class);
        $mockKgSearch->method('execute')->willReturn(collect());

        $action = new PlanWithKnowledgeAction($mockGateway, $mockResolver, $mockKgSearch);

        $result = $action->execute('Analyse competitor pricing', $this->team->id);

        $this->assertSame([], $result['first_principles']['insights']);
        $this->assertSame([], $result['first_principles']['risks']);
        $this->assertSame([], $result['first_principles']['key_questions']);
        // Other layers still populate (or are empty depending on data), no exception thrown
        $this->assertIsArray($result['memory_hits']);
    }
}
