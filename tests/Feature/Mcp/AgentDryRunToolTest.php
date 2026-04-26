<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentExecution;
use App\Domain\Marketplace\Models\MarketplaceListing;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Mcp\Tools\Agent\AgentDryRunTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Tests\TestCase;

class AgentDryRunToolTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $user;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Dry Run Team',
            'slug' => 'dry-run-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->actingAs($this->user);
        $this->agent = Agent::factory()->for($this->team)->create([
            'provider' => 'anthropic',
            'model' => 'claude-haiku-4-5',
        ]);

        app()->instance('mcp.team_id', $this->team->id);

        // Stub the AI gateway to avoid real API calls
        $this->app->instance(AiGatewayInterface::class, new StubAiGateway);
    }

    private function decode(Response $response): array
    {
        return json_decode((string) $response->content(), true);
    }

    public function test_returns_stubbed_output(): void
    {
        $tool = new AgentDryRunTool;
        $response = $tool->handle(new Request([
            'agent_id' => $this->agent->id,
            'input_message' => 'Hello world',
        ]));

        $payload = $this->decode($response);
        $this->assertFalse(
            $response->isError(),
            'Got error: '.json_encode($payload),
        );

        $this->assertSame($this->agent->id, $payload['agent_id']);
        $this->assertStringContainsString('Hello world', $payload['output']);
        $this->assertSame('claude-haiku-4-5', $payload['model']);
        $this->assertSame(7, $payload['cost_credits']);
        $this->assertSame(50, $payload['tokens_input']);
        $this->assertSame(25, $payload['tokens_output']);
        $this->assertSame(123, $payload['latency_ms']);
    }

    public function test_does_not_persist_agent_execution_row(): void
    {
        $beforeCount = AgentExecution::withoutGlobalScopes()->count();

        $tool = new AgentDryRunTool;
        $tool->handle(new Request([
            'agent_id' => $this->agent->id,
            'input_message' => 'persistence sentinel',
        ]));

        $afterCount = AgentExecution::withoutGlobalScopes()->count();

        $this->assertSame($beforeCount, $afterCount, 'Dry-run must not write AgentExecution rows');
    }

    public function test_blocks_marketplace_published_agent(): void
    {
        MarketplaceListing::create([
            'team_id' => $this->team->id,
            'published_by' => $this->user->id,
            'type' => 'agent',
            'listable_id' => $this->agent->id,
            'name' => 'Test',
            'slug' => 'test-listing',
            'description' => 'd',
            'status' => 'published',
            'visibility' => 'public',
            'version' => '1.0.0',
        ]);

        $tool = new AgentDryRunTool;
        $response = $tool->handle(new Request([
            'agent_id' => $this->agent->id,
            'input_message' => 'should be rejected',
        ]));

        $this->assertTrue($response->isError());
        $payload = json_decode((string) $response->content(), true);
        $this->assertSame('FAILED_PRECONDITION', $payload['error']['code']);
        $this->assertStringContainsString('marketplace', strtolower($payload['error']['message']));
    }

    public function test_cross_tenant_returns_not_found(): void
    {
        $otherUser = User::factory()->create();
        $otherTeam = Team::create([
            'name' => 'Other',
            'slug' => 'dry-run-other',
            'owner_id' => $otherUser->id,
            'settings' => [],
        ]);
        $otherAgent = Agent::factory()->for($otherTeam)->create();

        $tool = new AgentDryRunTool;
        $response = $tool->handle(new Request([
            'agent_id' => $otherAgent->id,
            'input_message' => 'hello',
        ]));

        $this->assertTrue($response->isError());
        $payload = json_decode((string) $response->content(), true);
        $this->assertSame('NOT_FOUND', $payload['error']['code']);
    }

    public function test_missing_team_returns_permission_denied(): void
    {
        app()->forgetInstance('mcp.team_id');
        \Illuminate\Support\Facades\Auth::logout();

        $tool = new AgentDryRunTool;
        $response = $tool->handle(new Request([
            'agent_id' => $this->agent->id,
            'input_message' => 'hello',
        ]));

        $this->assertTrue($response->isError());
        $payload = json_decode((string) $response->content(), true);
        $this->assertSame('PERMISSION_DENIED', $payload['error']['code']);
    }

    public function test_system_prompt_override_is_used(): void
    {
        $capturing = new CapturingAiGateway;
        $this->app->instance(AiGatewayInterface::class, $capturing);

        $tool = new AgentDryRunTool;
        $tool->handle(new Request([
            'agent_id' => $this->agent->id,
            'input_message' => 'x',
            'system_prompt_override' => 'YOU ARE A PIRATE',
        ]));

        $this->assertSame('YOU ARE A PIRATE', $capturing->lastSystemPrompt);
    }

    public function test_dry_run_writes_audit_entry(): void
    {
        $tool = new AgentDryRunTool;
        $tool->handle(new Request([
            'agent_id' => $this->agent->id,
            'input_message' => 'Hello world',
        ]));

        $this->assertDatabaseHas('audit_entries', [
            'event' => 'agent.dry_run',
            'subject_id' => $this->agent->id,
            'team_id' => $this->team->id,
        ]);
    }

    public function test_empty_input_message_validation_fails(): void
    {
        $tool = new AgentDryRunTool;

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $tool->handle(new Request([
            'agent_id' => $this->agent->id,
            'input_message' => '',
        ]));
    }
}

class StubAiGateway implements AiGatewayInterface
{
    public function complete(AiRequestDTO $request): AiResponseDTO
    {
        return new AiResponseDTO(
            content: 'stubbed-output: '.$request->userPrompt,
            parsedOutput: null,
            usage: new AiUsageDTO(promptTokens: 50, completionTokens: 25, costCredits: 7),
            provider: $request->provider,
            model: $request->model,
            latencyMs: 123,
        );
    }

    public function stream(AiRequestDTO $request, ?callable $onChunk = null): AiResponseDTO
    {
        return $this->complete($request);
    }

    public function estimateCost(AiRequestDTO $request): int
    {
        return 0;
    }
}

class CapturingAiGateway implements AiGatewayInterface
{
    public ?string $lastSystemPrompt = null;

    public function complete(AiRequestDTO $request): AiResponseDTO
    {
        $this->lastSystemPrompt = $request->systemPrompt;

        return new AiResponseDTO(
            content: 'ok',
            parsedOutput: null,
            usage: new AiUsageDTO(0, 0, 0),
            provider: $request->provider,
            model: $request->model,
            latencyMs: 0,
        );
    }

    public function stream(AiRequestDTO $request, ?callable $onChunk = null): AiResponseDTO
    {
        return $this->complete($request);
    }

    public function estimateCost(AiRequestDTO $request): int
    {
        return 0;
    }
}
