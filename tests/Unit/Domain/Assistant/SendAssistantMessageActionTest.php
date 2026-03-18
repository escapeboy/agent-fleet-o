<?php

namespace Tests\Unit\Domain\Assistant;

use App\Domain\Assistant\Actions\SendAssistantMessageAction;
use App\Domain\Assistant\Models\AssistantConversation;
use App\Domain\Assistant\Services\AssistantIntentClassifier;
use App\Domain\Assistant\Services\AssistantToolRegistry;
use App\Domain\Assistant\Services\ContextResolver;
use App\Domain\Assistant\Services\ConversationManager;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Services\LocalAgentDiscovery;
use App\Models\User;
use Mockery;
use Tests\TestCase;

class SendAssistantMessageActionTest extends TestCase
{
    private AiGatewayInterface $gateway;
    private ConversationManager $conversationManager;
    private ContextResolver $contextResolver;
    private AssistantToolRegistry $toolRegistry;
    private AssistantIntentClassifier $intentClassifier;
    private LocalAgentDiscovery $agentDiscovery;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = Mockery::mock(AiGatewayInterface::class);
        $this->conversationManager = Mockery::mock(ConversationManager::class);
        $this->contextResolver = Mockery::mock(ContextResolver::class);
        $this->toolRegistry = Mockery::mock(AssistantToolRegistry::class);
        $this->intentClassifier = Mockery::mock(AssistantIntentClassifier::class);
        $this->agentDiscovery = Mockery::mock(LocalAgentDiscovery::class);
    }

    private function makeAction(): SendAssistantMessageAction
    {
        return new SendAssistantMessageAction(
            $this->gateway,
            $this->conversationManager,
            $this->contextResolver,
            $this->toolRegistry,
            $this->intentClassifier,
            $this->agentDiscovery,
        );
    }

    private function makeUser(): User
    {
        $team = Mockery::mock(\App\Domain\Shared\Models\Team::class);
        $team->shouldReceive('getAttribute')->andReturn(null);

        $user = Mockery::mock(User::class);
        $user->shouldReceive('getAttribute')->andReturnUsing(function (string $key) use ($team) {
            return match ($key) {
                'id' => 'user-1',
                'name' => 'Test User',
                'current_team_id' => 'team-1',
                'currentTeam' => $team,
                default => null,
            };
        });
        $user->shouldReceive('teamRole')->withAnyArgs()->andReturn(null);
        $user->shouldReceive('offsetExists')->andReturn(false);

        return $user;
    }

    private function stubCommonExpectations(): void
    {
        $this->conversationManager->shouldReceive('addMessage');
        $this->conversationManager->shouldReceive('buildMessageHistory')->andReturn([]);
        $this->conversationManager->shouldReceive('generateTitle');
        $this->contextResolver->shouldReceive('resolve')->andReturn('');
        $this->toolRegistry->shouldReceive('getTools')->andReturn([]);
    }

    /**
     * When relay mode is active, a local provider (codex) should be rewritten
     * to bridge_agent so the request routes through the bridge daemon.
     */
    public function test_relay_mode_rewrites_local_provider_to_bridge_agent(): void
    {
        config(['llm_providers.codex' => ['local' => true, 'agent_key' => 'codex']]);
        $this->agentDiscovery->shouldReceive('isRelayMode')->andReturn(true);
        $this->stubCommonExpectations();

        $capturedRequest = null;
        $this->gateway->shouldReceive('complete')
            ->once()
            ->withArgs(function (AiRequestDTO $req) use (&$capturedRequest) {
                $capturedRequest = $req;
                return true;
            })
            ->andReturn(new AiResponseDTO(
                content: 'Bridge response',
                parsedOutput: null,
                usage: new AiUsageDTO(promptTokens: 10, completionTokens: 5, costCredits: 0),
                provider: 'bridge_agent',
                model: 'codex',
                latencyMs: 100,
            ));

        $conversation = Mockery::mock(AssistantConversation::class);
        $conversation->shouldReceive('getAttribute')->with('id')->andReturn('conv-1');

        $this->makeAction()->execute(
            $conversation, 'Hello', $this->makeUser(),
            provider: 'codex', model: 'o4-mini',
        );

        $this->assertNotNull($capturedRequest);
        $this->assertEquals('bridge_agent', $capturedRequest->provider);
        $this->assertEquals('codex', $capturedRequest->model);
    }

    /**
     * When relay mode is off, local providers stay as-is.
     */
    public function test_non_relay_mode_keeps_local_provider(): void
    {
        config(['llm_providers.codex' => ['local' => true, 'agent_key' => 'codex']]);
        $this->agentDiscovery->shouldReceive('isRelayMode')->andReturn(false);
        $this->stubCommonExpectations();

        $capturedRequest = null;
        $this->gateway->shouldReceive('complete')
            ->once()
            ->withArgs(function (AiRequestDTO $req) use (&$capturedRequest) {
                $capturedRequest = $req;
                return true;
            })
            ->andReturn(new AiResponseDTO(
                content: 'Local response',
                parsedOutput: null,
                usage: new AiUsageDTO(promptTokens: 10, completionTokens: 5, costCredits: 0),
                provider: 'codex',
                model: 'o4-mini',
                latencyMs: 100,
            ));

        $conversation = Mockery::mock(AssistantConversation::class);
        $conversation->shouldReceive('getAttribute')->with('id')->andReturn('conv-1');

        $this->makeAction()->execute(
            $conversation, 'Hello', $this->makeUser(),
            provider: 'codex', model: 'o4-mini',
        );

        $this->assertNotNull($capturedRequest);
        $this->assertEquals('codex', $capturedRequest->provider);
        $this->assertEquals('o4-mini', $capturedRequest->model);
    }

    /**
     * Cloud providers should not be affected by relay mode at all.
     */
    public function test_cloud_provider_unaffected_by_relay_mode(): void
    {
        config(['llm_providers.anthropic' => ['local' => false]]);
        $this->agentDiscovery->shouldReceive('isRelayMode')->andReturn(true);
        $this->stubCommonExpectations();

        $this->intentClassifier->shouldReceive('requiresToolCall')->andReturn(false);

        $capturedRequest = null;
        $this->gateway->shouldReceive('complete')
            ->once()
            ->withArgs(function (AiRequestDTO $req) use (&$capturedRequest) {
                $capturedRequest = $req;
                return true;
            })
            ->andReturn(new AiResponseDTO(
                content: 'Cloud response',
                parsedOutput: null,
                usage: new AiUsageDTO(promptTokens: 100, completionTokens: 50, costCredits: 10),
                provider: 'anthropic',
                model: 'claude-sonnet-4-5',
                latencyMs: 500,
            ));

        $conversation = Mockery::mock(AssistantConversation::class);
        $conversation->shouldReceive('getAttribute')->with('id')->andReturn('conv-1');

        $this->makeAction()->execute(
            $conversation, 'Hello', $this->makeUser(),
            provider: 'anthropic', model: 'claude-sonnet-4-5',
        );

        $this->assertNotNull($capturedRequest);
        $this->assertEquals('anthropic', $capturedRequest->provider);
        $this->assertEquals('claude-sonnet-4-5', $capturedRequest->model);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
