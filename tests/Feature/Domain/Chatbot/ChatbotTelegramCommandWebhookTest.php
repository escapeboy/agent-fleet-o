<?php

namespace Tests\Feature\Domain\Chatbot;

use App\Domain\Agent\Models\Agent;
use App\Domain\Chatbot\Jobs\ProcessChatbotTelegramMessageJob;
use App\Domain\Chatbot\Models\Chatbot;
use App\Domain\Chatbot\Models\ChatbotChannel;
use App\Domain\Chatbot\Models\ChatbotToken;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatbotTelegramCommandWebhookTest extends TestCase
{
    use RefreshDatabase;

    private string $tokenPrefix = 'cmd12345';

    private string $botToken = '123456:TESTHASH';

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
            'config' => ['bot_token' => $this->botToken],
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

    public function test_start_command_sends_welcome_and_does_not_dispatch_job(): void
    {
        $this->seedChatbotChannel();
        Bus::fake();
        Http::fake();

        $response = $this->postJson("/api/chatbot/telegram/{$this->tokenPrefix}", [
            'message' => [
                'chat' => ['id' => 99, 'type' => 'private'],
                'text' => '/start',
                'from' => ['id' => 42, 'username' => 'alice'],
            ],
        ]);

        $response->assertStatus(200);

        Bus::assertNotDispatched(ProcessChatbotTelegramMessageJob::class);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage')
            && (string) $request['chat_id'] === '99'
            && str_contains($request['text'], 'виртуалният асистент на Barsy'));
    }

    public function test_start_command_with_bot_suffix_in_group_sends_welcome_and_does_not_dispatch_job(): void
    {
        $this->seedChatbotChannel();
        Bus::fake();
        Http::fake();

        $response = $this->postJson("/api/chatbot/telegram/{$this->tokenPrefix}", [
            'message' => [
                'chat' => ['id' => -100, 'type' => 'group'],
                'text' => '/start@SomeBot',
                'from' => ['id' => 42, 'username' => 'alice'],
                'entities' => [['type' => 'bot_command', 'offset' => 0, 'length' => 14]],
            ],
        ]);

        $response->assertStatus(200);

        Bus::assertNotDispatched(ProcessChatbotTelegramMessageJob::class);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage')
            && (string) $request['chat_id'] === '-100'
            && str_contains($request['text'], 'виртуалният асистент на Barsy'));
    }

    public function test_unknown_command_is_silently_ignored(): void
    {
        $this->seedChatbotChannel();
        Bus::fake();
        Http::fake();

        $response = $this->postJson("/api/chatbot/telegram/{$this->tokenPrefix}", [
            'message' => [
                'chat' => ['id' => 99, 'type' => 'private'],
                'text' => '/foo',
                'from' => ['id' => 42, 'username' => 'alice'],
            ],
        ]);

        $response->assertStatus(200);

        Bus::assertNotDispatched(ProcessChatbotTelegramMessageJob::class);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/sendMessage'));
    }
}
