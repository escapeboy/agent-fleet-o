<?php

namespace Tests\Feature\Domain\Chatbot;

use App\Domain\Agent\Models\Agent;
use App\Domain\Chatbot\Enums\ChatbotType;
use App\Domain\Chatbot\Enums\KnowledgeSourceStatus;
use App\Domain\Chatbot\Jobs\IndexKnowledgeSourceJob;
use App\Domain\Chatbot\Models\Chatbot;
use App\Domain\Chatbot\Models\ChatbotKnowledgeSource;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\EmbeddingProviderInterface;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class IndexKnowledgeSourceEmbeddingTest extends TestCase
{
    use RefreshDatabase;

    public function test_source_fails_with_clear_message_when_team_has_no_embedding_key(): void
    {
        Storage::fake();
        Storage::put('kb/doc.txt', 'Доставката до София е безплатна над 50 лв.');

        $user = User::factory()->create();
        $team = Team::create([
            'name' => 'T '.bin2hex(random_bytes(3)),
            'slug' => 't-'.bin2hex(random_bytes(3)),
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $chatbot = Chatbot::create([
            'team_id' => $team->id,
            'agent_id' => Agent::factory()->create(['team_id' => $team->id])->id,
            'name' => 'Help',
            'slug' => 'help-'.bin2hex(random_bytes(3)),
            'type' => ChatbotType::HelpBot,
        ]);
        $source = ChatbotKnowledgeSource::create([
            'chatbot_id' => $chatbot->id,
            'team_id' => $team->id,
            'type' => 'document',
            'name' => 'Delivery',
            'source_data' => ['path' => 'kb/doc.txt'],
        ]);

        $embedding = Mockery::mock(EmbeddingProviderInterface::class);
        $embedding->shouldReceive('embedForTeam')->once()->with(Mockery::type('string'), $team->id)->andReturnNull();
        $embedding->shouldNotReceive('embed');
        $this->app->instance(EmbeddingProviderInterface::class, $embedding);

        (new IndexKnowledgeSourceJob($source->id))->handle();

        $source->refresh();
        $this->assertSame(KnowledgeSourceStatus::Failed, $source->status);
        $this->assertStringContainsString('embedding', $source->error_message);
    }
}
