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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatbotTelegramGroupWebhookTest extends TestCase
{
    use RefreshDatabase;

    private string $tokenPrefix = 'grp12345';

    /** Numeric prefix so the bot user id (123456) is derivable from the token. */
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

    private function fakeGetMe(): void
    {
        Http::fake([
            '*getMe*' => Http::response(['ok' => true, 'result' => ['id' => 123456, 'username' => 'fleetbot']]),
            '*' => Http::response('', 200),
        ]);
    }

    public function test_group_chatter_without_mention_or_reply_is_ignored(): void
    {
        $this->seedChatbotChannel();
        Bus::fake();
        $this->fakeGetMe();

        $response = $this->postJson("/api/chatbot/telegram/{$this->tokenPrefix}", [
            'message' => [
                'chat' => ['id' => -100, 'type' => 'group'],
                'text' => 'just two members chatting',
                'from' => ['id' => 42, 'username' => 'alice'],
            ],
        ]);

        $response->assertStatus(200);
        Bus::assertNotDispatched(ProcessChatbotTelegramMessageJob::class);
    }

    public function test_group_mention_dispatches_with_per_user_session_and_stripped_text(): void
    {
        $this->seedChatbotChannel();
        Bus::fake();
        $this->fakeGetMe();

        $response = $this->postJson("/api/chatbot/telegram/{$this->tokenPrefix}", [
            'message' => [
                'chat' => ['id' => -100, 'type' => 'supergroup'],
                'text' => '@fleetbot what is the price?',
                'from' => ['id' => 42, 'username' => 'alice'],
                'entities' => [['type' => 'mention', 'offset' => 0, 'length' => 8]],
            ],
        ]);

        $response->assertStatus(200);
        Bus::assertDispatched(
            ProcessChatbotTelegramMessageJob::class,
            fn (ProcessChatbotTelegramMessageJob $job) => $job->text === 'what is the price?'
                && $job->chatId === '-100'
                && $job->sessionExternalId === '-100:42',
        );
    }

    public function test_group_reply_to_bot_dispatches_with_per_user_session(): void
    {
        $this->seedChatbotChannel();
        Bus::fake();
        $this->fakeGetMe();

        $response = $this->postJson("/api/chatbot/telegram/{$this->tokenPrefix}", [
            'message' => [
                'chat' => ['id' => -100, 'type' => 'group'],
                'text' => 'and what about shipping?',
                'from' => ['id' => 77, 'username' => 'bob'],
                'reply_to_message' => ['from' => ['id' => 123456, 'is_bot' => true]],
            ],
        ]);

        $response->assertStatus(200);
        Bus::assertDispatched(
            ProcessChatbotTelegramMessageJob::class,
            fn (ProcessChatbotTelegramMessageJob $job) => $job->text === 'and what about shipping?'
                && $job->chatId === '-100'
                && $job->sessionExternalId === '-100:77',
        );
    }

    public function test_private_message_dispatches_with_chat_id_session_unchanged(): void
    {
        $this->seedChatbotChannel();
        Bus::fake();

        $response = $this->postJson("/api/chatbot/telegram/{$this->tokenPrefix}", [
            'message' => [
                'chat' => ['id' => 99, 'type' => 'private'],
                'text' => 'Hello there',
                'from' => ['id' => 42, 'username' => 'alice'],
            ],
        ]);

        $response->assertStatus(200);
        Bus::assertDispatched(
            ProcessChatbotTelegramMessageJob::class,
            fn (ProcessChatbotTelegramMessageJob $job) => $job->text === 'Hello there'
                && $job->chatId === '99'
                && $job->sessionExternalId === '99',
        );
    }

    public function test_group_thumbs_down_sets_pending_comment_key_per_voter(): void
    {
        $this->seedChatbotChannel();
        Bus::fake();
        $this->fakeGetMe();

        $response = $this->postJson("/api/chatbot/telegram/{$this->tokenPrefix}", [
            'callback_query' => [
                'id' => 'cb-1',
                'data' => 'fb:down:msg-123',
                'from' => ['id' => 42, 'username' => 'alice'],
                'message' => ['message_id' => 555, 'chat' => ['id' => -100, 'type' => 'group']],
            ],
        ]);

        $response->assertStatus(200);

        // Pending-comment state is keyed per (chat, voter).
        $this->assertSame('msg-123', Cache::get('tg:fbcomment:-100:42'));

        // A DIFFERENT user's plain message must NOT be consumed as the comment;
        // since they aren't addressing the bot it is ignored entirely.
        $other = $this->postJson("/api/chatbot/telegram/{$this->tokenPrefix}", [
            'message' => [
                'chat' => ['id' => -100, 'type' => 'group'],
                'text' => 'unrelated message from bob',
                'from' => ['id' => 77, 'username' => 'bob'],
            ],
        ]);

        $other->assertStatus(200);
        Bus::assertNotDispatched(ProcessChatbotTelegramMessageJob::class);
        // Alice's pending comment is still waiting for HER next message.
        $this->assertSame('msg-123', Cache::get('tg:fbcomment:-100:42'));
    }
}
