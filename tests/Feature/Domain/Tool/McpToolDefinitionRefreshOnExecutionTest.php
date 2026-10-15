<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Tool;

use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use App\Domain\Shared\Models\Team;
use App\Domain\Tool\Actions\ResolveAgentToolsAction;
use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Models\Tool;
use App\Domain\Tool\Services\McpHttpClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * MCP tool definitions used to come only from the stored row, refreshed hourly
 * by tools:refresh-definitions. The MCP clients are one-shot, so a server's
 * notifications/tools/list_changed has nowhere to arrive; instead each agent
 * execution re-lists the server when it resolves its tools.
 */
class McpToolDefinitionRefreshOnExecutionTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private Agent $agent;

    private Tool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tools.definition_pinning.enabled' => false]);

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Refresh Team',
            'slug' => 'refresh-'.Str::lower(Str::random(6)),
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $this->team->users()->attach($user, ['role' => 'owner']);

        $this->agent = Agent::create([
            'id' => (string) Str::uuid7(),
            'team_id' => $this->team->id,
            'name' => 'Bank Agent',
            'slug' => 'bank-agent-'.Str::lower(Str::random(4)),
            'role' => 'assistant',
            'goal' => 'pay',
            'backstory' => 'test',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet',
            'status' => AgentStatus::Active,
            'config' => [],
        ]);

        $this->tool = Tool::factory()->create([
            'team_id' => $this->team->id,
            'type' => ToolType::McpHttp,
            'status' => ToolStatus::Active,
            'transport_config' => ['url' => 'https://bank.example.test'],
            'tool_definitions' => [$this->def('transfer')],
        ]);
        $this->agent->tools()->attach($this->tool->id, ['priority' => 0]);
    }

    /**
     * @return array<string, mixed>
     */
    private function def(string $name): array
    {
        return [
            'name' => $name,
            'description' => "{$name} money",
            'inputSchema' => ['type' => 'object', 'properties' => ['amount' => ['type' => 'number']]],
        ];
    }

    /**
     * @return list<string>
     */
    private function resolvedNames(): array
    {
        return collect(app(ResolveAgentToolsAction::class)->execute($this->agent))
            ->map(fn ($t) => $t->name())
            ->values()
            ->all();
    }

    public function test_changed_server_tool_list_is_used_by_the_next_execution(): void
    {
        $this->mock(McpHttpClient::class)->shouldReceive('listTools')
            ->with('https://bank.example.test', [])
            ->andReturn([$this->def('transfer'), $this->def('refund')]);

        $names = $this->resolvedNames();

        $this->assertContains('transfer', $names);
        $this->assertContains('refund', $names);
        $this->assertSame(['transfer', 'refund'], array_column($this->tool->fresh()->tool_definitions, 'name'));
    }

    public function test_listing_failure_keeps_stored_definitions(): void
    {
        $this->mock(McpHttpClient::class)->shouldReceive('listTools')
            ->andThrow(new RuntimeException('server down'));

        $names = $this->resolvedNames();

        $this->assertContains('transfer', $names);
        $this->assertSame(['transfer'], array_column($this->tool->fresh()->tool_definitions, 'name'));
    }

    public function test_pinning_holds_the_change_instead_of_exposing_it(): void
    {
        config(['tools.definition_pinning.enabled' => true, 'decision_rubric.enabled' => false]);
        $this->mock(McpHttpClient::class)->shouldReceive('listTools')
            ->andReturn([$this->def('transfer'), $this->def('drain_account')]);

        $names = $this->resolvedNames();

        $this->assertContains('transfer', $names);
        $this->assertNotContains('drain_account', $names);
        $this->assertNotNull($this->tool->fresh()->pending_tool_definitions);
    }

    public function test_refresh_can_be_disabled(): void
    {
        config(['tools.refresh_definitions_on_execution' => false]);
        $this->mock(McpHttpClient::class)->shouldNotReceive('listTools');

        $this->assertContains('transfer', $this->resolvedNames());
    }

    public function test_shared_platform_tool_is_not_rewritten_from_one_teams_execution(): void
    {
        $platform = Tool::factory()->create([
            'team_id' => null,
            'is_platform' => true,
            'type' => ToolType::McpHttp,
            'status' => ToolStatus::Active,
            'transport_config' => ['url' => 'https://shared.example.test'],
            'tool_definitions' => [$this->def('lookup')],
        ]);
        $this->agent->tools()->attach($platform->id, ['priority' => 1]);

        $this->mock(McpHttpClient::class)->shouldReceive('listTools')
            ->with('https://bank.example.test', [])
            ->andReturn([$this->def('transfer')]);

        $this->assertContains('lookup', $this->resolvedNames());
        $this->assertSame(['lookup'], array_column($platform->fresh()->tool_definitions, 'name'));
    }
}
