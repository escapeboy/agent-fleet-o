<?php

namespace Tests\Feature\Domain\Agent;

use App\Domain\Agent\Actions\ExecuteAgentAction;
use App\Domain\Agent\Models\Agent;
use App\Domain\Budget\Enums\LedgerType;
use App\Domain\Budget\Models\CreditLedger;
use App\Domain\Shared\Models\Team;
use App\Domain\Tool\Actions\ResolveAgentToolsAction;
use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Models\Tool;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * An agent WITH a tool attached but whose tools resolve to EMPTY (unreachable /
 * misconfigured mcp_http, empty tool_definitions) used to fall through to the
 * skill chain, which fails hard with "Agent has no skills or tools assigned" for
 * a tool-only agent — bricking every reply. With a task in the input we now
 * degrade gracefully to a plain LLM completion instead.
 */
class GracefulToolDegradationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team-gtd',
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

        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')->andReturn(new AiResponseDTO(
            content: 'graceful completion response',
            parsedOutput: null,
            usage: new AiUsageDTO(promptTokens: 10, completionTokens: 10, costCredits: 1),
            provider: 'test',
            model: 'test-model',
            latencyMs: 100,
        ))->byDefault();
        $this->app->instance(AiGatewayInterface::class, $gateway);
    }

    public function test_tool_only_agent_with_unresolvable_tool_completes_via_direct_prompt(): void
    {
        $agent = Agent::factory()->create(['team_id' => $this->team->id]);

        // A misconfigured mcp_http tool with no usable tool definitions —
        // $agentHasTools is true, but ResolveAgentToolsAction yields nothing.
        $tool = Tool::withoutGlobalScopes()->create([
            'team_id' => $this->team->id,
            'name' => 'Unreachable MCP',
            'slug' => 'unreachable-mcp',
            'type' => ToolType::McpHttp,
            'status' => ToolStatus::Active,
            'transport_config' => ['url' => 'http://127.0.0.1:1/unreachable'],
            'tool_definitions' => [],
        ]);
        $agent->tools()->attach($tool->id, ['priority' => 0]);

        // Force the "resolved to empty" condition deterministically.
        $resolver = Mockery::mock(ResolveAgentToolsAction::class);
        $resolver->shouldReceive('execute')->andReturn([]);
        $this->app->instance(ResolveAgentToolsAction::class, $resolver);

        $result = app(ExecuteAgentAction::class)->execute(
            agent: $agent,
            input: ['task' => 'What is the capital of France?'],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertEquals('completed', $result['execution']->status);
        $this->assertNotEmpty($result['output']);
        $this->assertStringNotContainsString(
            'no skills or tools assigned',
            $result['execution']->error_message ?? '',
        );
    }
}
