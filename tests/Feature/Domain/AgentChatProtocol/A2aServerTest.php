<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\AgentChatProtocol;

use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use App\Domain\AgentChatProtocol\Enums\AgentChatVisibility;
use App\Domain\AgentChatProtocol\Enums\MessageDirection;
use App\Domain\AgentChatProtocol\Enums\MessageStatus;
use App\Domain\AgentChatProtocol\Enums\MessageType;
use App\Domain\AgentChatProtocol\Events\ChatMessageReceived;
use App\Domain\AgentChatProtocol\Models\AgentChatMessage;
use App\Domain\AgentChatProtocol\Services\HmacJwtVerifier;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Server side of A2A: an external peer holding a conversation with one of our
 * agents over JSON-RPC.
 */
class A2aServerTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ThrottleRequests::class, ThrottleRequestsWithRedis::class]);
        config(['agent_chat.a2a.server_enabled' => true]);

        // The agent itself runs on a queued listener; these tests are about the
        // protocol surface, so the reply is written directly where it matters.
        Event::fake([ChatMessageReceived::class]);

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'A2A Server Team',
            'slug' => 'a2a-server-team-'.Str::random(6),
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
    }

    // ── transport + lifecycle ────────────────────────────────────────────────

    public function test_message_send_accepts_a_peer_message_and_returns_a_submitted_task(): void
    {
        $agent = $this->agent(AgentChatVisibility::Team);
        Sanctum::actingAs($this->user, ['*']);

        $response = $this->postJson($this->rpcUrl($agent), $this->rpc('message/send', [
            'message' => [
                'role' => 'user', 'kind' => 'message',
                'messageId' => (string) Str::uuid7(),
                'parts' => [['kind' => 'text', 'text' => 'What is the status?']],
            ],
        ]));

        $response->assertOk();
        $this->assertSame('2.0', $response->json('jsonrpc'));
        $this->assertSame('task', $response->json('result.kind'));
        $this->assertSame('submitted', $response->json('result.status.state'));

        $taskId = $response->json('result.id');
        $this->assertDatabaseHas('agent_chat_messages', [
            'msg_id' => $taskId,
            'agent_id' => $agent->id,
            'direction' => MessageDirection::Inbound->value,
        ]);
        $this->assertSame('What is the status?', AgentChatMessage::withoutGlobalScopes()
            ->where('msg_id', $taskId)->value('payload')['content']);
    }

    public function test_tasks_get_reports_working_until_the_agent_replies_then_completed_with_an_artifact(): void
    {
        $agent = $this->agent(AgentChatVisibility::Team);
        Sanctum::actingAs($this->user, ['*']);

        $taskId = $this->postJson($this->rpcUrl($agent), $this->rpc('message/send', [
            'message' => ['parts' => [['kind' => 'text', 'text' => 'ping']]],
        ]))->json('result.id');

        $this->postJson($this->rpcUrl($agent), $this->rpc('tasks/get', ['id' => $taskId]))
            ->assertOk()
            ->assertJsonPath('result.status.state', 'working');

        $this->writeReply($agent, $taskId, 'pong from the agent');

        $done = $this->postJson($this->rpcUrl($agent), $this->rpc('tasks/get', ['id' => $taskId]));
        $done->assertOk();
        $done->assertJsonPath('result.status.state', 'completed');
        $this->assertSame('pong from the agent', $done->json('result.artifacts.0.parts.0.text'));
        // History carries both turns so a peer can reconstruct the exchange.
        $this->assertCount(2, $done->json('result.history'));
        $this->assertSame('agent', $done->json('result.history.1.role'));
    }

    public function test_tasks_get_reports_failed_when_the_agent_run_failed(): void
    {
        $agent = $this->agent(AgentChatVisibility::Team);
        Sanctum::actingAs($this->user, ['*']);

        $taskId = $this->postJson($this->rpcUrl($agent), $this->rpc('message/send', [
            'message' => ['parts' => [['kind' => 'text', 'text' => 'boom']]],
        ]))->json('result.id');

        AgentChatMessage::withoutGlobalScopes()->where('msg_id', $taskId)
            ->update(['status' => MessageStatus::Failed->value, 'error' => 'model refused']);

        $response = $this->postJson($this->rpcUrl($agent), $this->rpc('tasks/get', ['id' => $taskId]));

        $response->assertJsonPath('result.status.state', 'failed');
        $this->assertSame('model refused', $response->json('result.status.message.parts.0.text'));
    }

    // ── JSON-RPC error surface ───────────────────────────────────────────────

    public function test_unknown_task_id_returns_task_not_found_rather_than_an_http_error(): void
    {
        $agent = $this->agent(AgentChatVisibility::Team);
        Sanctum::actingAs($this->user, ['*']);

        $response = $this->postJson($this->rpcUrl($agent), $this->rpc('tasks/get', ['id' => (string) Str::uuid7()]));

        $response->assertOk();
        $response->assertJsonPath('error.code', -32001);
    }

    public function test_a_task_id_from_another_agent_does_not_resolve(): void
    {
        $agentA = $this->agent(AgentChatVisibility::Team);
        $agentB = $this->agent(AgentChatVisibility::Team);
        Sanctum::actingAs($this->user, ['*']);

        $taskId = $this->postJson($this->rpcUrl($agentA), $this->rpc('message/send', [
            'message' => ['parts' => [['kind' => 'text', 'text' => 'for A only']]],
        ]))->json('result.id');

        $this->postJson($this->rpcUrl($agentB), $this->rpc('tasks/get', ['id' => $taskId]))
            ->assertJsonPath('error.code', -32001);
    }

    public function test_unknown_method_returns_method_not_found(): void
    {
        $agent = $this->agent(AgentChatVisibility::Team);
        Sanctum::actingAs($this->user, ['*']);

        $this->postJson($this->rpcUrl($agent), $this->rpc('message/stream', []))
            ->assertJsonPath('error.code', -32601);
    }

    public function test_cancel_is_refused_with_the_spec_error_instead_of_pretending(): void
    {
        $agent = $this->agent(AgentChatVisibility::Team);
        Sanctum::actingAs($this->user, ['*']);

        $this->postJson($this->rpcUrl($agent), $this->rpc('tasks/cancel', ['id' => 'x']))
            ->assertJsonPath('error.code', -32002);
    }

    public function test_message_without_a_text_part_returns_invalid_params(): void
    {
        $agent = $this->agent(AgentChatVisibility::Team);
        Sanctum::actingAs($this->user, ['*']);

        $this->postJson($this->rpcUrl($agent), $this->rpc('message/send', ['message' => ['parts' => []]]))
            ->assertJsonPath('error.code', -32602);
    }

    public function test_wrong_jsonrpc_version_returns_invalid_request(): void
    {
        $agent = $this->agent(AgentChatVisibility::Team);
        Sanctum::actingAs($this->user, ['*']);

        $this->postJson($this->rpcUrl($agent), ['jsonrpc' => '1.0', 'id' => 1, 'method' => 'tasks/get'])
            ->assertJsonPath('error.code', -32600);
    }

    public function test_a_non_uuid_peer_message_id_is_accepted_and_correlated(): void
    {
        $agent = $this->agent(AgentChatVisibility::Team);
        Sanctum::actingAs($this->user, ['*']);

        // A2A allows any string id; our protocol validator requires UUIDs.
        $response = $this->postJson($this->rpcUrl($agent), $this->rpc('message/send', [
            'message' => ['messageId' => 'peer-abc-123', 'parts' => [['kind' => 'text', 'text' => 'hi']]],
        ]));

        $response->assertOk();
        $taskId = $response->json('result.id');
        $this->assertTrue(Str::isUuid($taskId));

        $payload = AgentChatMessage::withoutGlobalScopes()->where('msg_id', $taskId)->value('payload');
        $this->assertSame('peer-abc-123', $payload['metadata']['peer_message_id']);
    }

    // ── authorization + gating ───────────────────────────────────────────────

    public function test_server_disabled_returns_404(): void
    {
        config(['agent_chat.a2a.server_enabled' => false]);
        $agent = $this->agent(AgentChatVisibility::Team);
        Sanctum::actingAs($this->user, ['*']);

        $this->postJson($this->rpcUrl($agent), $this->rpc('tasks/get', ['id' => 'x']))
            ->assertStatus(404);
    }

    public function test_public_agent_requires_a_signed_jwt(): void
    {
        $agent = $this->agent(AgentChatVisibility::Public);
        $agent->update(['chat_protocol_secret' => 'a2a-secret']);

        $this->postJson($this->rpcUrl($agent), $this->rpc('message/send', [
            'message' => ['parts' => [['kind' => 'text', 'text' => 'hi']]],
        ]))->assertStatus(401);

        $token = app(HmacJwtVerifier::class)->sign(['sub' => 'ext:peer'], 'a2a-secret', 60);

        $this->postJson($this->rpcUrl($agent), $this->rpc('message/send', [
            'message' => ['parts' => [['kind' => 'text', 'text' => 'hi']]],
        ]), ['Authorization' => 'Bearer '.$token])->assertOk();
    }

    public function test_team_agent_is_not_reachable_from_another_team(): void
    {
        $agent = $this->agent(AgentChatVisibility::Team);

        $outsider = User::factory()->create();
        $otherTeam = Team::create([
            'name' => 'Other', 'slug' => 'other-'.Str::random(6),
            'owner_id' => $outsider->id, 'settings' => [],
        ]);
        $outsider->update(['current_team_id' => $otherTeam->id]);

        Sanctum::actingAs($outsider, ['*']);

        $this->postJson($this->rpcUrl($agent), $this->rpc('tasks/get', ['id' => 'x']))
            ->assertStatus(404);
    }

    // ── discovery ────────────────────────────────────────────────────────────

    public function test_public_agent_card_is_reachable_without_auth_and_points_at_the_rpc_endpoint(): void
    {
        $agent = $this->agent(AgentChatVisibility::Public);

        $response = $this->getJson('/.well-known/agents/'.$agent->chat_protocol_slug.'/agent-card.json');

        $response->assertOk();
        $response->assertJsonPath('url', url('/api/v1/agents/'.$agent->id.'/a2a'));
        $response->assertJsonPath('preferredTransport', 'JSONRPC');
        $response->assertJsonPath('supportedInterfaces.0.protocolBinding', 'JSONRPC');
    }

    public function test_private_agent_card_is_not_published(): void
    {
        $agent = $this->agent(AgentChatVisibility::Private);

        $this->getJson('/.well-known/agents/'.$agent->chat_protocol_slug.'/agent-card.json')
            ->assertStatus(404);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function rpcUrl(Agent $agent): string
    {
        return '/api/v1/agents/'.$agent->id.'/a2a';
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function rpc(string $method, array $params): array
    {
        return ['jsonrpc' => '2.0', 'id' => (string) Str::uuid7(), 'method' => $method, 'params' => $params];
    }

    private function agent(AgentChatVisibility $visibility): Agent
    {
        return Agent::create([
            'id' => (string) Str::uuid7(),
            'team_id' => $this->team->id,
            'name' => 'A2A Server Agent '.Str::random(4),
            'slug' => 'a2a-srv-'.Str::random(8),
            'role' => 'assistant',
            'goal' => 'answer peers',
            'backstory' => 'test',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet',
            'status' => AgentStatus::Active,
            'chat_protocol_enabled' => true,
            'chat_protocol_visibility' => $visibility->value,
            'chat_protocol_slug' => 'a2a-srv-'.Str::random(8),
        ]);
    }

    /**
     * Stand in for ExecuteAgentOnChatMessage, which is queued and faked here.
     */
    private function writeReply(Agent $agent, string $inReplyTo, string $text): void
    {
        $inbound = AgentChatMessage::withoutGlobalScopes()->where('msg_id', $inReplyTo)->firstOrFail();

        AgentChatMessage::create([
            'id' => (string) Str::uuid7(),
            'team_id' => $agent->team_id,
            'session_id' => $inbound->session_id,
            'agent_id' => $agent->id,
            'direction' => MessageDirection::Outbound,
            'message_type' => MessageType::ChatMessage,
            'msg_id' => (string) Str::uuid7(),
            'in_reply_to' => $inReplyTo,
            'from_identifier' => 'agent',
            'to_identifier' => 'a2a:peer',
            'status' => MessageStatus::Delivered,
            'payload' => ['content' => $text],
        ]);
    }
}
