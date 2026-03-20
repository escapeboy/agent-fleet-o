<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Agent\Actions\ExecuteAgentAction;
use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentExecution;
use App\Domain\Budget\Exceptions\InsufficientBudgetException;
use App\Domain\Crew\Models\Crew;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use Mockery;

class OpenAiCompatibleControllerTest extends ApiTestCase
{
    private function createAgent(array $overrides = []): Agent
    {
        return Agent::create(array_merge([
            'team_id' => $this->team->id,
            'name' => 'Test Agent',
            'slug' => 'test-agent',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-5',
            'status' => 'active',
            'config' => [],
            'capabilities' => [],
            'constraints' => [],
            'budget_spent_credits' => 0,
        ], $overrides));
    }

    private function createCrew(array $overrides = []): Crew
    {
        // Crew requires coordinator + QA agent IDs
        $coordinator = $this->createAgent(['slug' => 'crew-coord-'.uniqid()]);
        $qa = $this->createAgent(['slug' => 'crew-qa-'.uniqid()]);

        return Crew::create(array_merge([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'coordinator_agent_id' => $coordinator->id,
            'qa_agent_id' => $qa->id,
            'name' => 'Test Crew',
            'slug' => 'test-crew',
            'status' => 'active',
            'process_type' => 'sequential',
            'max_task_iterations' => 3,
            'quality_threshold' => 0.7,
            'settings' => [],
        ], $overrides));
    }

    // ── List Models ─────────────────────────────────────────────

    public function test_list_models_requires_auth(): void
    {
        $response = $this->getJson('/v1/models');

        $response->assertUnauthorized();
    }

    public function test_list_models_returns_agents_and_crews(): void
    {
        $this->actingAsApiUser();
        $this->createAgent(['name' => 'My Agent', 'slug' => 'my-agent']);
        $this->createCrew(['name' => 'My Crew', 'slug' => 'my-crew']);

        $response = $this->getJson('/v1/models');

        $response->assertOk()
            ->assertJsonStructure([
                'object',
                'data' => [['id', 'object', 'created', 'owned_by']],
            ])
            ->assertJsonPath('object', 'list');

        $modelIds = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains('agent/my-agent', $modelIds);
        $this->assertContains('crew/my-crew', $modelIds);
    }

    public function test_list_models_excludes_inactive_agents(): void
    {
        $this->actingAsApiUser();
        $this->createAgent(['slug' => 'active-one', 'status' => 'active']);
        $this->createAgent(['slug' => 'disabled-one', 'status' => 'disabled']);

        $response = $this->getJson('/v1/models');
        $response->assertOk();

        $modelIds = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains('agent/active-one', $modelIds);
        $this->assertNotContains('agent/disabled-one', $modelIds);
    }

    public function test_list_models_includes_provider_models(): void
    {
        $this->actingAsApiUser();

        $response = $this->getJson('/v1/models');
        $response->assertOk();

        $modelIds = collect($response->json('data'))->pluck('id')->toArray();
        // Should include at least some provider models from llm_pricing config
        $hasProviderModel = collect($modelIds)->contains(fn ($id) => str_contains($id, '/') && ! str_starts_with($id, 'agent/') && ! str_starts_with($id, 'crew/'));
        $this->assertTrue($hasProviderModel, 'Should include at least one raw provider model');
    }

    // ── Retrieve Model ──────────────────────────────────────────

    public function test_retrieve_model_returns_agent(): void
    {
        $this->actingAsApiUser();
        $this->createAgent(['slug' => 'my-agent']);

        $response = $this->getJson('/v1/models/agent/my-agent');

        $response->assertOk()
            ->assertJsonPath('id', 'agent/my-agent')
            ->assertJsonPath('object', 'model')
            ->assertJsonPath('owned_by', 'fleetq');
    }

    public function test_retrieve_model_returns_404_for_unknown(): void
    {
        $this->actingAsApiUser();

        $response = $this->getJson('/v1/models/agent/nonexistent');

        $response->assertNotFound()
            ->assertJsonPath('error.type', 'invalid_request_error')
            ->assertJsonPath('error.code', 'model_not_found');
    }

    // ── Chat Completions ────────────────────────────────────────

    public function test_chat_completions_requires_auth(): void
    {
        $response = $this->postJson('/v1/chat/completions', [
            'model' => 'anthropic/claude-sonnet-4-5',
            'messages' => [['role' => 'user', 'content' => 'Hello']],
        ]);

        $response->assertUnauthorized();
    }

    public function test_chat_completions_validates_request(): void
    {
        $this->actingAsApiUser();

        $response = $this->postJson('/v1/chat/completions', []);

        $response->assertUnprocessable();
    }

    public function test_chat_completions_validates_message_roles(): void
    {
        $this->actingAsApiUser();

        $response = $this->postJson('/v1/chat/completions', [
            'model' => 'anthropic/claude-sonnet-4-5',
            'messages' => [['role' => 'invalid_role', 'content' => 'Hello']],
        ]);

        $response->assertUnprocessable();
    }

    public function test_chat_completions_returns_404_for_unknown_model(): void
    {
        $this->actingAsApiUser();

        $response = $this->postJson('/v1/chat/completions', [
            'model' => 'agent/nonexistent',
            'messages' => [['role' => 'user', 'content' => 'Hello']],
        ]);

        $response->assertNotFound()
            ->assertJsonPath('error.code', 'model_not_found');
    }

    public function test_chat_completions_passthrough_non_streaming(): void
    {
        $this->actingAsApiUser();

        $fakeResponse = new AiResponseDTO(
            content: 'Hello! How can I help you?',
            parsedOutput: null,
            usage: new AiUsageDTO(promptTokens: 10, completionTokens: 20, costCredits: 5),
            provider: 'anthropic',
            model: 'claude-sonnet-4-5',
            latencyMs: 100,
        );

        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')->once()->andReturn($fakeResponse);
        $this->app->instance(AiGatewayInterface::class, $gateway);

        $response = $this->postJson('/v1/chat/completions', [
            'model' => 'anthropic/claude-sonnet-4-5',
            'messages' => [['role' => 'user', 'content' => 'Hello']],
            'stream' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('object', 'chat.completion')
            ->assertJsonPath('choices.0.message.role', 'assistant')
            ->assertJsonPath('choices.0.message.content', 'Hello! How can I help you?')
            ->assertJsonPath('choices.0.finish_reason', 'stop')
            ->assertJsonStructure([
                'id', 'object', 'created', 'model',
                'choices' => [['index', 'message', 'finish_reason']],
                'usage' => ['prompt_tokens', 'completion_tokens', 'total_tokens'],
            ]);
    }

    public function test_chat_completions_agent_non_streaming(): void
    {
        $this->actingAsApiUser();
        $agent = $this->createAgent(['slug' => 'helper']);

        $fakeExecution = new AgentExecution;
        $fakeExecution->input_tokens = 15;
        $fakeExecution->output_tokens = 25;

        $executeAction = Mockery::mock(ExecuteAgentAction::class);
        $executeAction->shouldReceive('execute')
            ->once()
            ->andReturn([
                'output' => 'Agent response here',
                'execution' => $fakeExecution,
            ]);
        $this->app->instance(ExecuteAgentAction::class, $executeAction);

        $response = $this->postJson('/v1/chat/completions', [
            'model' => 'agent/helper',
            'messages' => [['role' => 'user', 'content' => 'Help me']],
            'stream' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('object', 'chat.completion')
            ->assertJsonPath('choices.0.message.content', 'Agent response here')
            ->assertJsonPath('usage.prompt_tokens', 15)
            ->assertJsonPath('usage.completion_tokens', 25)
            ->assertJsonPath('usage.total_tokens', 40)
            ->assertJsonPath('system_fingerprint', 'fleetq-v1');
    }

    public function test_chat_completions_streaming_returns_sse(): void
    {
        $this->actingAsApiUser();

        $fakeResponse = new AiResponseDTO(
            content: 'Streamed response',
            parsedOutput: null,
            usage: new AiUsageDTO(promptTokens: 10, completionTokens: 20, costCredits: 5),
            provider: 'anthropic',
            model: 'claude-sonnet-4-5',
            latencyMs: 100,
        );

        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('stream')
            ->once()
            ->andReturnUsing(function ($request, $onChunk) use ($fakeResponse) {
                // Simulate streaming chunks
                if ($onChunk) {
                    $onChunk('Streamed ');
                    $onChunk('response');
                }

                return $fakeResponse;
            });
        $this->app->instance(AiGatewayInterface::class, $gateway);

        $response = $this->postJson('/v1/chat/completions', [
            'model' => 'anthropic/claude-sonnet-4-5',
            'messages' => [['role' => 'user', 'content' => 'Hello']],
            'stream' => true,
        ]);

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/event-stream; charset=utf-8');
    }

    public function test_chat_completions_returns_402_on_insufficient_budget(): void
    {
        $this->actingAsApiUser();

        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')
            ->once()
            ->andThrow(new InsufficientBudgetException('Budget exceeded'));
        $this->app->instance(AiGatewayInterface::class, $gateway);

        $response = $this->postJson('/v1/chat/completions', [
            'model' => 'anthropic/claude-sonnet-4-5',
            'messages' => [['role' => 'user', 'content' => 'Hello']],
        ]);

        $response->assertStatus(402)
            ->assertJsonPath('error.type', 'insufficient_quota')
            ->assertJsonPath('error.code', 'insufficient_quota');
    }

    public function test_chat_completions_returns_500_on_unexpected_error(): void
    {
        $this->actingAsApiUser();

        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')
            ->once()
            ->andThrow(new \RuntimeException('Something went wrong'));
        $this->app->instance(AiGatewayInterface::class, $gateway);

        $response = $this->postJson('/v1/chat/completions', [
            'model' => 'anthropic/claude-sonnet-4-5',
            'messages' => [['role' => 'user', 'content' => 'Hello']],
        ]);

        $response->assertStatus(500)
            ->assertJsonPath('error.type', 'server_error');
    }

    // ── Request Translator ──────────────────────────────────────

    public function test_bare_slug_resolves_to_agent(): void
    {
        $this->actingAsApiUser();
        $agent = $this->createAgent(['slug' => 'my-helper']);

        $fakeExecution = new AgentExecution;
        $fakeExecution->input_tokens = 5;
        $fakeExecution->output_tokens = 10;

        $executeAction = Mockery::mock(ExecuteAgentAction::class);
        $executeAction->shouldReceive('execute')->once()->andReturn([
            'output' => 'OK',
            'execution' => $fakeExecution,
        ]);
        $this->app->instance(ExecuteAgentAction::class, $executeAction);

        $response = $this->postJson('/v1/chat/completions', [
            'model' => 'my-helper',
            'messages' => [['role' => 'user', 'content' => 'Test']],
        ]);

        $response->assertOk()
            ->assertJsonPath('choices.0.message.content', 'OK');
    }
}
