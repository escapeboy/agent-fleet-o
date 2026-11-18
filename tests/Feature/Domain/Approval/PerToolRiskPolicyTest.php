<?php

namespace Tests\Feature\Domain\Approval;

use App\Domain\Approval\Models\ActionProposal;
use App\Domain\Assistant\Actions\SendAssistantMessageAction;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Prism\Prism\Tool as PrismToolObject;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Exercises the per-tool rules (settings.action_proposal_policy.tools) of
 * SendAssistantMessageAction's private approval gate. A rule for a named
 * tool replaces the risk-tier rule for that tool.
 */
class PerToolRiskPolicyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'T '.bin2hex(random_bytes(3)),
            'slug' => 't-'.bin2hex(random_bytes(3)),
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
    }

    public function test_per_tool_ask_creates_proposal_even_when_all_tiers_auto(): void
    {
        $this->setPolicy(['low' => 'auto', 'medium' => 'auto', 'high' => 'auto', 'tools' => ['list_agents' => 'ask']]);

        $tools = $this->wrapTools([
            $this->fakeTool('list_agents', 'list-ok'),
            $this->fakeTool('get_agent', 'get-ok'),
        ]);

        $result = $this->invokeFirst($tools, 'list_agents');

        $this->assertStringContainsString('proposed for human review', $result);
        $this->assertSame(1, ActionProposal::count());
        $proposal = ActionProposal::first();
        $this->assertSame('tool_call', $proposal->target_type);
        $this->assertSame('list_agents', $proposal->payload['tool']);
        $this->assertSame('tool', $proposal->payload['rule']);

        // Other tools keep their tier rule (auto).
        $this->assertSame('get-ok', $this->invokeFirst($tools, 'get_agent'));
        $this->assertSame(1, ActionProposal::count());
    }

    public function test_per_tool_reject_refuses_without_running_or_proposing(): void
    {
        $this->setPolicy(['low' => 'auto', 'medium' => 'auto', 'high' => 'auto', 'tools' => ['create_agent' => 'reject']]);

        $ran = false;
        $tool = (new PrismToolObject)
            ->as('create_agent')
            ->for('test tool create_agent')
            ->using(function () use (&$ran) {
                $ran = true;

                return 'should-not-run';
            });

        $result = $this->invokeFirst($this->wrapTools([$tool]), 'create_agent');

        $this->assertStringContainsString('refused by team policy', $result);
        $this->assertStringContainsString('per-tool rule', $result);
        $this->assertStringContainsString("'create_agent'", $result);
        $this->assertFalse($ran);
        $this->assertSame(0, ActionProposal::count());
    }

    public function test_per_tool_auto_replaces_stricter_tier_rule(): void
    {
        $this->setPolicy(['low' => 'auto', 'medium' => 'auto', 'high' => 'ask', 'tools' => ['delete_agent' => 'auto']]);

        $tools = $this->wrapTools([$this->fakeTool('delete_agent', 'delete-ok')]);

        $this->assertSame('delete-ok', $this->invokeFirst($tools, 'delete_agent'));
        $this->assertSame(0, ActionProposal::count());
    }

    public function test_tool_not_in_map_still_follows_its_tier(): void
    {
        $this->setPolicy(['low' => 'auto', 'medium' => 'auto', 'high' => 'ask', 'tools' => ['delete_agent' => 'auto']]);

        $tools = $this->wrapTools([
            $this->fakeTool('delete_agent', 'delete-ok'),
            $this->fakeTool('memory_delete', 'memory-delete-ok'),
        ]);

        $result = $this->invokeFirst($tools, 'memory_delete');

        $this->assertStringContainsString('proposed for human review', $result);
        $this->assertSame(1, ActionProposal::count());
        $this->assertArrayNotHasKey('rule', ActionProposal::first()->payload);
    }

    public function test_unknown_per_tool_value_is_ignored(): void
    {
        $this->setPolicy(['low' => 'auto', 'medium' => 'auto', 'high' => 'auto', 'tools' => ['list_agents' => 'NONSENSE']]);

        $tools = $this->wrapTools([$this->fakeTool('list_agents', 'list-ok')]);

        $this->assertSame('list-ok', $this->invokeFirst($tools, 'list_agents'));
        $this->assertSame(0, ActionProposal::count());
    }

    public function test_non_array_tools_value_is_ignored(): void
    {
        $this->setPolicy(['low' => 'auto', 'medium' => 'auto', 'high' => 'ask', 'tools' => 'list_agents']);

        $tools = $this->wrapTools([
            $this->fakeTool('list_agents', 'list-ok'),
            $this->fakeTool('delete_agent', 'delete-ok'),
        ]);

        $this->assertSame('list-ok', $this->invokeFirst($tools, 'list_agents'));
        $this->assertStringContainsString('proposed for human review', $this->invokeFirst($tools, 'delete_agent'));
        $this->assertSame(1, ActionProposal::count());
    }

    /**
     * @param  array<string, mixed>  $policy
     */
    private function setPolicy(array $policy): void
    {
        $this->team->update(['settings' => ['action_proposal_policy' => $policy]]);
        $this->user->refresh();
    }

    /**
     * @param  array<PrismToolObject>  $tools
     * @return array<PrismToolObject>
     */
    private function wrapTools(array $tools): array
    {
        $action = app(SendAssistantMessageAction::class);
        $method = new ReflectionMethod($action, 'wrapToolsWithSlowModeGate');
        $method->setAccessible(true);

        return $method->invoke($action, $tools, $this->user, null);
    }

    private function fakeTool(string $name, string $result): PrismToolObject
    {
        return (new PrismToolObject)
            ->as($name)
            ->for("test tool {$name}")
            ->using(fn () => $result);
    }

    /**
     * @param  array<PrismToolObject>  $tools
     */
    private function invokeFirst(array $tools, string $name): string
    {
        foreach ($tools as $tool) {
            if ($tool->name() === $name) {
                $fn = (new ReflectionProperty(PrismToolObject::class, 'fn'))->getValue($tool);

                return (string) $fn();
            }
        }
        $this->fail("Tool {$name} not in wrapped set");
    }
}
