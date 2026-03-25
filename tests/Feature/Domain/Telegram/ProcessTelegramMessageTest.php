<?php

namespace Tests\Feature\Domain\Telegram;

use App\Domain\Assistant\Actions\SendAssistantMessageAction;
use App\Domain\Assistant\Models\AssistantConversation;
use App\Domain\Shared\Models\Team;
use App\Domain\Telegram\Actions\ProcessTelegramMessageAction;
use App\Domain\Telegram\Jobs\ProcessTelegramMessageJob;
use App\Domain\Telegram\Models\TelegramBot;
use App\Domain\Telegram\Models\TelegramChatBinding;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProcessTelegramMessageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    private TelegramBot $bot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ThrottleRequests::class, ThrottleRequestsWithRedis::class]);

        Queue::fake();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);

        $this->bot = TelegramBot::create([
            'team_id' => $this->team->id,
            'bot_token' => 'test-bot-token-123',
            'bot_username' => 'testbot',
            'bot_name' => 'Test Bot',
            'routing_mode' => 'assistant',
            'status' => 'active',
            'webhook_secret' => 'test-webhook-secret',
        ]);
    }

    private function webhookPayload(string $text, string $chatId = '123456'): array
    {
        return [
            'update_id' => 1,
            'message' => [
                'message_id' => 100,
                'from' => ['id' => 999, 'username' => 'testuser'],
                'chat' => ['id' => (int) $chatId, 'type' => 'private'],
                'text' => $text,
                'date' => time(),
            ],
        ];
    }

    public function test_webhook_dispatches_job_for_active_bot(): void
    {
        $response = $this->postJson(
            "/api/telegram/webhook/{$this->team->id}",
            $this->webhookPayload('Hello'),
            ['X-Telegram-Bot-Api-Secret-Token' => 'test-webhook-secret'],
        );

        $response->assertStatus(200);
        Queue::assertPushed(ProcessTelegramMessageJob::class, function ($job) {
            return $job->botId === $this->bot->id;
        });
    }

    public function test_webhook_returns_200_for_unknown_team_silently(): void
    {
        $response = $this->postJson(
            '/api/telegram/webhook/non-existent-team-id',
            $this->webhookPayload('Hello'),
        );

        $response->assertStatus(200);
        Queue::assertNothingPushed();
    }

    public function test_webhook_returns_200_silently_on_secret_mismatch(): void
    {
        $this->bot->update(['webhook_secret' => 'correct-secret', 'webhook_mode' => true]);

        $response = $this->postJson(
            "/api/telegram/webhook/{$this->team->id}",
            $this->webhookPayload('Hello'),
            ['X-Telegram-Bot-Api-Secret-Token' => 'wrong-secret'],
        );

        $response->assertStatus(200);
        Queue::assertNothingPushed();
    }

    public function test_webhook_accepts_valid_secret_token(): void
    {
        $this->bot->update(['webhook_secret' => 'my-secret', 'webhook_mode' => true]);

        $response = $this->postJson(
            "/api/telegram/webhook/{$this->team->id}",
            $this->webhookPayload('Hello'),
            ['X-Telegram-Bot-Api-Secret-Token' => 'my-secret'],
        );

        $response->assertStatus(200);
        Queue::assertPushed(ProcessTelegramMessageJob::class);
    }

    public function test_webhook_ignores_empty_text(): void
    {
        $payload = $this->webhookPayload('');

        $response = $this->postJson(
            "/api/telegram/webhook/{$this->team->id}",
            $payload,
            ['X-Telegram-Bot-Api-Secret-Token' => 'test-webhook-secret'],
        );

        $response->assertStatus(200);
        Queue::assertNothingPushed();
    }

    public function test_start_command_sends_welcome_message(): void
    {
        Http::fake([
            'https://api.telegram.org/bot*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
            'https://api.telegram.org/bot*/editMessageText' => Http::response(['ok' => true], 200),
            'https://api.telegram.org/bot*/sendChatAction' => Http::response(['ok' => true], 200),
        ]);

        $action = app(ProcessTelegramMessageAction::class);
        $action->execute($this->bot, '123456', '/start', 'testuser');

        Http::assertSent(function ($request) {
            // Should call sendMessage
            return str_contains($request->url(), 'sendMessage');
        });
    }

    public function test_help_command_sends_help_text(): void
    {
        Http::fake([
            'https://api.telegram.org/bot*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
            'https://api.telegram.org/bot*/sendChatAction' => Http::response(['ok' => true], 200),
        ]);

        $action = app(ProcessTelegramMessageAction::class);
        $action->execute($this->bot, '123456', '/help');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'sendMessage')
                && str_contains($request['text'] ?? '', 'Available commands');
        });
    }

    public function test_regular_message_calls_ai_assistant(): void
    {
        Http::fake([
            'https://api.telegram.org/bot*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
            'https://api.telegram.org/bot*/editMessageText' => Http::response(['ok' => true], 200),
            'https://api.telegram.org/bot*/sendChatAction' => Http::response(['ok' => true], 200),
        ]);

        // Pre-create a conversation with a user so action doesn't try to create one
        $conversation = AssistantConversation::create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'title' => 'Telegram test',
            'context_type' => 'telegram',
            'context_id' => null,
        ]);

        // Pre-create the binding linked to user and conversation
        TelegramChatBinding::create([
            'team_id' => $this->team->id,
            'chat_id' => 'chat-999',
            'user_id' => $this->user->id,
            'conversation_id' => $conversation->id,
        ]);

        // Mock the AI assistant to return a simple response
        $this->mock(SendAssistantMessageAction::class, function ($mock) {
            $mock->shouldReceive('executeStreaming')
                ->once()
                ->andReturn(new AiResponseDTO(
                    content: 'Hello! How can I help you?',
                    parsedOutput: null,
                    usage: new AiUsageDTO(5, 10, 0),
                    model: 'claude-haiku-4-5',
                    provider: 'anthropic',
                    latencyMs: 0,
                ));
        });

        $action = app(ProcessTelegramMessageAction::class);
        $action->execute($this->bot, 'chat-999', 'Hello assistant', 'testuser');

        // Binding remains intact after the action runs
        $this->assertDatabaseHas('telegram_chat_bindings', [
            'team_id' => $this->team->id,
            'chat_id' => 'chat-999',
        ]);
    }
}
