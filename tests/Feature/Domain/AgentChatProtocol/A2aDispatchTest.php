<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\AgentChatProtocol;

use App\Domain\AgentChatProtocol\DTOs\ChatMessageDTO;
use App\Domain\AgentChatProtocol\Enums\AdapterKind;
use App\Domain\AgentChatProtocol\Enums\ExternalAgentStatus;
use App\Domain\AgentChatProtocol\Exceptions\A2aDispatchNotSupportedException;
use App\Domain\AgentChatProtocol\Models\ExternalAgent;
use App\Domain\AgentChatProtocol\Services\ProtocolDispatcher;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class A2aDispatchTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'A2A Dispatch',
            'slug' => 'a2a-dispatch-'.substr(Str::uuid7()->toString(), 0, 8),
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($user, ['role' => 'owner']);

        config([
            'services.ssrf.validate_host' => false,
            'agent_chat.a2a.dispatch_enabled' => true,
            'agent_chat.a2a.task_poll_delay_ms' => 0,
        ]);
    }

    private function a2aAgent(): ExternalAgent
    {
        return ExternalAgent::create([
            'id' => Str::uuid7()->toString(),
            'team_id' => $this->team->id,
            'name' => 'A2A Agent',
            'slug' => 'a2a-peer-'.substr(Str::uuid7()->toString(), 0, 6),
            'endpoint_url' => 'https://recipe.example.com/a2a/v1',
            'adapter_kind' => AdapterKind::A2a->value,
            'protocol_version' => 'a2a',
            'status' => ExternalAgentStatus::Active,
        ]);
    }

    private function chat(ExternalAgent $agent, string $content): ChatMessageDTO
    {
        return ChatMessageDTO::fromArray([
            'session_id' => 'sess-1',
            'from' => 'fleetq:test',
            'to' => $agent->endpoint_url,
            'content' => $content,
        ]);
    }

    public function test_sends_message_send_and_returns_immediate_message_reply(): void
    {
        Http::fake([
            'https://recipe.example.com/a2a/v1' => Http::response([
                'jsonrpc' => '2.0',
                'id' => 'x',
                'result' => [
                    'kind' => 'message',
                    'messageId' => 'm-reply',
                    'role' => 'agent',
                    'parts' => [['kind' => 'text', 'text' => 'Hi back']],
                ],
            ], 200),
        ]);

        $agent = $this->a2aAgent();
        $result = app(ProtocolDispatcher::class)->sendChat($agent, $this->chat($agent, 'hello'));

        $this->assertSame('Hi back', $result['content']);
        $this->assertSame('m-reply', $result['msg_id']);
        $this->assertSame($agent->slug, $result['from']);

        Http::assertSent(fn ($request) => $request->url() === 'https://recipe.example.com/a2a/v1'
            && $request->data()['method'] === 'message/send'
            && $request->data()['params']['message']['parts'][0]['text'] === 'hello');
    }

    public function test_polls_tasks_get_until_task_completes(): void
    {
        Http::fake(function ($request) {
            $method = $request->data()['method'] ?? '';

            if ($method === 'message/send') {
                return Http::response(['jsonrpc' => '2.0', 'result' => [
                    'kind' => 'task', 'id' => 't1', 'status' => ['state' => 'working'],
                ]], 200);
            }

            // tasks/get → now completed with an artifact
            return Http::response(['jsonrpc' => '2.0', 'result' => [
                'kind' => 'task', 'id' => 't1', 'status' => ['state' => 'completed'],
                'artifacts' => [['parts' => [['kind' => 'text', 'text' => 'Done: 42']]]],
            ]], 200);
        });

        $agent = $this->a2aAgent();
        $result = app(ProtocolDispatcher::class)->sendChat($agent, $this->chat($agent, 'compute'));

        $this->assertSame('Done: 42', $result['content']);
        Http::assertSent(fn ($request) => ($request->data()['method'] ?? '') === 'tasks/get');
    }

    public function test_failed_task_state_throws(): void
    {
        Http::fake([
            'https://recipe.example.com/a2a/v1' => Http::response(['result' => [
                'kind' => 'task', 'id' => 't9', 'status' => [
                    'state' => 'failed',
                    'message' => ['parts' => [['kind' => 'text', 'text' => 'boom']]],
                ],
            ]], 200),
        ]);

        $this->expectException(\RuntimeException::class);

        $agent = $this->a2aAgent();
        app(ProtocolDispatcher::class)->sendChat($agent, $this->chat($agent, 'x'));
    }

    public function test_dispatch_disabled_throws_and_sends_nothing(): void
    {
        config(['agent_chat.a2a.dispatch_enabled' => false]);
        Http::fake();

        $agent = $this->a2aAgent();

        try {
            app(ProtocolDispatcher::class)->sendChat($agent, $this->chat($agent, 'x'));
            $this->fail('Expected A2aDispatchNotSupportedException when dispatch disabled.');
        } catch (A2aDispatchNotSupportedException $e) {
            $this->assertStringContainsString('disabled', $e->getMessage());
        }

        Http::assertNothingSent();
    }
}
