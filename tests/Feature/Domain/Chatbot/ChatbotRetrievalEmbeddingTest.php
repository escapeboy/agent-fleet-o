<?php

namespace Tests\Feature\Domain\Chatbot;

use App\Domain\Agent\Actions\ExecuteAgentAction;
use App\Domain\Agent\Models\Agent;
use App\Domain\Chatbot\Enums\ChatbotType;
use App\Domain\Chatbot\Models\Chatbot;
use App\Domain\Chatbot\Services\ChatbotResponseService;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\EmbeddingProviderInterface;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class ChatbotRetrievalEmbeddingTest extends TestCase
{
    use RefreshDatabase;

    private function chatbot(): Chatbot
    {
        $user = User::factory()->create();
        $team = Team::create([
            'name' => 'T '.bin2hex(random_bytes(3)),
            'slug' => 't-'.bin2hex(random_bytes(3)),
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $agent = Agent::factory()->create(['team_id' => $team->id]);

        return Chatbot::create([
            'team_id' => $team->id,
            'agent_id' => $agent->id,
            'name' => 'Help',
            'slug' => 'help-'.bin2hex(random_bytes(3)),
            'type' => ChatbotType::HelpBot,
        ]);
    }

    private function retrieve(EmbeddingProviderInterface $embedding, Chatbot $chatbot, string $query): array
    {
        $service = new ChatbotResponseService(app(ExecuteAgentAction::class), $embedding);
        $m = new ReflectionMethod($service, 'retrieveRelevantChunks');

        return $m->invoke($service, $chatbot, $query);
    }

    public function test_query_is_embedded_with_the_fleetq_embedding_provider(): void
    {
        $embedding = Mockery::mock(EmbeddingProviderInterface::class);
        $chatbot = $this->chatbot();
        $embedding->shouldReceive('embedForTeam')->once()->with('Колко струва доставката?', $chatbot->team_id)->andReturn([0.1, 0.2]);
        $embedding->shouldReceive('formatForPgvector')->once()->with([0.1, 0.2])->andReturn('[0.1,0.2]');

        // SQLite has no pgvector, so the query itself fails and is swallowed;
        // what matters here is which provider produced the query vector.
        $this->assertSame([], $this->retrieve($embedding, $chatbot, 'Колко струва доставката?'));
    }

    public function test_missing_embedding_key_degrades_to_no_chunks(): void
    {
        $embedding = Mockery::mock(EmbeddingProviderInterface::class);
        $embedding->shouldReceive('embedForTeam')->once()->andReturnNull();
        $embedding->shouldNotReceive('formatForPgvector');

        $this->assertSame([], $this->retrieve($embedding, $this->chatbot(), 'hello'));
    }

    public function test_embedding_failure_degrades_to_no_chunks(): void
    {
        $embedding = Mockery::mock(EmbeddingProviderInterface::class);
        $embedding->shouldReceive('embedForTeam')->once()->andThrow(new \RuntimeException('provider down'));
        $embedding->shouldNotReceive('formatForPgvector');

        $this->assertSame([], $this->retrieve($embedding, $this->chatbot(), 'hello'));
    }
}
