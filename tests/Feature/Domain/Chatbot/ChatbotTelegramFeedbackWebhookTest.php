<?php

namespace Tests\Feature\Domain\Chatbot;

use App\Domain\Agent\Models\Agent;
use App\Domain\Chatbot\Contracts\ChatbotFeedbackRecorderInterface;
use App\Domain\Chatbot\Jobs\ProcessChatbotTelegramMessageJob;
use App\Domain\Chatbot\Models\Chatbot;
use App\Domain\Chatbot\Models\ChatbotChannel;
use App\Domain\Chatbot\Models\ChatbotToken;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class ChatbotTelegramFeedbackWebhookTest extends TestCase
{
    use RefreshDatabase;

    private string $tokenPrefix = 'abcd1234';

    private function seedChatbotChannel(): void
    {
        $user = User::factory()->create();
        $team = Team::create([
            'name' => 'T '.bin2hex(random_bytes(3)),
            'slug' => 't-'.bin2hex(random_bytes(3)),
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $agent = Agent::factory()->create(['team_id' => $team->id]);

        $chatbot = Chatbot::create([
            'team_id' => $team->id,
            'agent_id' => $agent->id,
            'name' => 'Bot',
            'slug' => 'bot-'.bin2hex(random_bytes(3)),
            'type' => 'custom',
            'status' => 'active',
        ]);

        ChatbotChannel::create([
            'chatbot_id' => $chatbot->id,
            'channel_type' => 'telegram',
            'config' => ['bot_token' => 'BOT:TOKEN'],
            'is_active' => true,
        ]);

        ChatbotToken::create([
            'chatbot_id' => $chatbot->id,
            'team_id' => $team->id,
            'name' => 'tg',
            'token_prefix' => $this->tokenPrefix,
            'token_hash' => hash('sha256', 'secret'),
        ]);
    }

    public function test_feedback_callback_records_vote_and_does_not_dispatch_job(): void
    {
        $this->seedChatbotChannel();
        Bus::fake();
        Http::fake();

        $recorder = Mockery::mock(ChatbotFeedbackRecorderInterface::class);
        $recorder->shouldReceive('record')
            ->once()
            ->with('msg-123', 'thumbs_down');
        $this->app->instance(ChatbotFeedbackRecorderInterface::class, $recorder);

        $response = $this->postJson("/api/chatbot/telegram/{$this->tokenPrefix}", [
            'callback_query' => [
                'id' => 'cb-1',
                'data' => 'fb:down:msg-123',
                'from' => ['username' => 'tester'],
                'message' => ['chat' => ['id' => 99]],
            ],
        ]);

        $response->assertStatus(200);

        Bus::assertNotDispatched(ProcessChatbotTelegramMessageJob::class);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/answerCallbackQuery')
            && $request['callback_query_id'] === 'cb-1');
    }

    public function test_normal_message_still_dispatches_job(): void
    {
        $this->seedChatbotChannel();
        Bus::fake();

        $response = $this->postJson("/api/chatbot/telegram/{$this->tokenPrefix}", [
            'message' => [
                'chat' => ['id' => 99],
                'text' => 'Hello there',
                'from' => ['username' => 'tester'],
            ],
        ]);

        $response->assertStatus(200);

        Bus::assertDispatched(
            ProcessChatbotTelegramMessageJob::class,
            fn (ProcessChatbotTelegramMessageJob $job) => $job->text === 'Hello there' && $job->chatId === '99',
        );
    }
}
