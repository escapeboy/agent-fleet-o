<?php

namespace Tests\Feature\Domain\Chatbot;

use App\Domain\Agent\Actions\ExecuteAgentAction;
use App\Domain\Agent\Models\Agent;
use App\Domain\Chatbot\Enums\ChatbotType;
use App\Domain\Chatbot\Models\Chatbot;
use App\Domain\Chatbot\Services\ChatbotAnswerCache;
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

    private function service(EmbeddingProviderInterface $embedding): ChatbotResponseService
    {
        return new ChatbotResponseService(app(ExecuteAgentAction::class), $embedding, app(ChatbotAnswerCache::class));
    }

    private function invokePrivate(object $obj, string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod($obj, $method))->invoke($obj, ...$args);
    }

    public function test_query_is_embedded_with_the_fleetq_embedding_provider(): void
    {
        $embedding = Mockery::mock(EmbeddingProviderInterface::class);
        $chatbot = $this->chatbot();
        $embedding->shouldReceive('embedForTeam')->once()->with('Колко струва доставката?', $chatbot->team_id)->andReturn([0.1, 0.2]);
        $embedding->shouldReceive('formatForPgvector')->once()->with([0.1, 0.2])->andReturn('[0.1,0.2]');
        $service = $this->service($embedding);

        $vector = $this->invokePrivate($service, 'embedQuery', $chatbot, 'Колко струва доставката?');

        $this->assertSame([0.1, 0.2], $vector);
        // SQLite has no pgvector, so the query itself fails and is swallowed;
        // what matters here is which provider produced the query vector.
        $this->assertSame([], $this->invokePrivate($service, 'retrieveRelevantChunks', $chatbot, $vector));
    }

    public function test_missing_embedding_key_degrades_to_no_chunks(): void
    {
        $embedding = Mockery::mock(EmbeddingProviderInterface::class);
        $embedding->shouldReceive('embedForTeam')->once()->andReturnNull();
        $embedding->shouldNotReceive('formatForPgvector');
        $service = $this->service($embedding);
        $chatbot = $this->chatbot();

        $vector = $this->invokePrivate($service, 'embedQuery', $chatbot, 'hello');

        $this->assertNull($vector);
        $this->assertSame([], $this->invokePrivate($service, 'retrieveRelevantChunks', $chatbot, $vector));
    }

    public function test_embedding_failure_degrades_to_no_vector(): void
    {
        $embedding = Mockery::mock(EmbeddingProviderInterface::class);
        $embedding->shouldReceive('embedForTeam')->once()->andThrow(new \RuntimeException('provider down'));

        $this->assertNull($this->invokePrivate($this->service($embedding), 'embedQuery', $this->chatbot(), 'hello'));
    }
}
