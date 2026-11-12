<?php

namespace Tests\Feature\Domain\Agent;

use App\Domain\Agent\Actions\ExecuteAgentAction;
use App\Domain\Agent\Models\Agent;
use App\Domain\Bridge\Enums\BridgeConnectionStatus;
use App\Domain\Bridge\Models\BridgeConnection;
use App\Domain\Budget\Enums\LedgerType;
use App\Domain\Budget\Models\CreditLedger;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Exceptions\BridgeNotConnectedException;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class ExecuteAgentBridgeOfflineTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    private MockInterface $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Bridge Offline Exec Team',
            'slug' => 'bridge-offline-exec',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);

        CreditLedger::withoutGlobalScopes()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'type' => LedgerType::Purchase,
            'amount' => 100000,
            'balance_after' => 100000,
            'description' => 'Test credit',
        ]);

        $this->gateway = Mockery::mock(AiGatewayInterface::class);
        $this->app->instance(AiGatewayInterface::class, $this->gateway);
    }

    private function bridgeAgent(array $config = []): Agent
    {
        return Agent::factory()->create([
            'team_id' => $this->team->id,
            'provider' => 'bridge_llm',
            'model' => 'llama3.2',
            'config' => $config,
        ]);
    }

    private function stubGatewayResponse(): void
    {
        $response = new AiResponseDTO(
            content: 'ok',
            parsedOutput: null,
            usage: new AiUsageDTO(promptTokens: 1, completionTokens: 1, costCredits: 0),
            provider: 'bridge_llm',
            model: 'llama3.2',
            latencyMs: 10,
        );
        $this->gateway->shouldReceive('complete')->andReturn($response)->byDefault();
        $this->gateway->shouldReceive('stream')->andReturn($response)->byDefault();
        $this->gateway->shouldReceive('estimateCost')->andReturn(0)->byDefault();
    }

    private function runAgent(Agent $agent): array
    {
        return app(ExecuteAgentAction::class)->execute(
            agent: $agent,
            input: ['task' => 'hello'],
            teamId: $this->team->id,
            userId: $this->user->id,
        );
    }

    public function test_bridge_agent_without_connection_is_refused_before_any_llm_call(): void
    {
        $this->gateway->shouldNotReceive('complete');
        $this->gateway->shouldNotReceive('stream');

        $result = $this->runAgent($this->bridgeAgent());

        $this->assertNull($result['output']);
        $this->assertSame('failed', $result['execution']->status);
        $this->assertSame(
            BridgeNotConnectedException::notConnected()->getMessage(),
            $result['execution']->error_message,
        );
    }

    public function test_bridge_agent_with_fallback_chain_is_not_refused(): void
    {
        $this->stubGatewayResponse();

        $result = $this->runAgent($this->bridgeAgent([
            'fallback_chain' => [['provider' => 'anthropic', 'model' => 'claude-sonnet-4-5']],
        ]));

        $this->assertNotSame(
            BridgeNotConnectedException::notConnected()->getMessage(),
            $result['execution']->error_message ?? '',
        );
    }

    public function test_provider_resolution_error_still_returns_failed_execution(): void
    {
        $this->gateway->shouldNotReceive('complete');
        $this->gateway->shouldNotReceive('stream');
        $this->gateway->shouldReceive('estimateCost')->andReturn(0)->byDefault();

        $this->team->update(['allowed_models' => ['anthropic/claude-sonnet-4-5']]);

        $agent = Agent::factory()->create([
            'team_id' => $this->team->id,
            'provider' => 'openai',
            'model' => 'gpt-4o',
            'config' => [],
        ]);

        $result = $this->runAgent($agent);

        $this->assertNull($result['output']);
        $this->assertSame('failed', $result['execution']->status);
        $this->assertNotEmpty($result['execution']->error_message);
        $this->assertNotSame(
            BridgeNotConnectedException::notConnected()->getMessage(),
            $result['execution']->error_message,
        );
    }

    public function test_bridge_agent_with_active_connection_is_not_refused(): void
    {
        $this->stubGatewayResponse();

        BridgeConnection::create([
            'team_id' => $this->team->id,
            'session_id' => 'relay-offline-test',
            'priority' => 1,
            'status' => BridgeConnectionStatus::Connected,
            'endpoints' => ['agents' => [], 'llm_endpoints' => [
                ['name' => 'ollama', 'base_url' => 'http://127.0.0.1:11434', 'online' => true, 'models' => ['llama3.2']],
            ]],
            'connected_at' => now(),
            'last_seen_at' => now(),
        ]);

        $result = $this->runAgent($this->bridgeAgent());

        $this->assertNotSame(
            BridgeNotConnectedException::notConnected()->getMessage(),
            $result['execution']->error_message ?? '',
        );
    }
}
