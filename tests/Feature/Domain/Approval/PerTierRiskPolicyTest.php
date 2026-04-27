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
 * Exercises the per-tier risk policy directly through
 * SendAssistantMessageAction's private gate. A controlled set of fake
 * Prism tools is fed in (one per risk level); the gate's wrapped output
 * is then invoked to verify the action: 'auto' (passes through to
 * underlying fn), 'ask' (creates a proposal + returns placeholder),
 * 'reject' (returns refusal without creating a proposal).
 */
class PerTierRiskPolicyTest extends TestCase
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

    public function test_default_policy_passes_all_tools_through(): void
    {
        // No slow_mode and no explicit policy → all tiers auto.
        $tools = $this->wrapTools([$this->fakeTool('list_agents', 'list-result')]);

        $this->assertSame(0, ActionProposal::count());
        $this->assertSame('list-result', $this->invokeFirst($tools, 'list_agents'));
    }

    public function test_legacy_slow_mode_gates_destructive_only(): void
    {
        $this->team->update(['settings' => ['slow_mode_enabled' => true]]);
        $this->user->refresh();

        $tools = $this->wrapTools([
            $this->fakeTool('list_agents', 'list-ok'),
            $this->fakeTool('create_agent', 'create-ok'),
            $this->fakeTool('delete_agent', 'delete-ok'),
        ]);

        $this->assertSame('list-ok', $this->invokeFirst($tools, 'list_agents'));
        $this->assertSame('create-ok', $this->invokeFirst($tools, 'create_agent'));

        $deleteResult = $this->invokeFirst($tools, 'delete_agent');
        $this->assertStringContainsString('proposed for human review', $deleteResult);
        $this->assertSame(1, ActionProposal::count());
        $this->assertSame('high', ActionProposal::first()->risk_level);
    }

    public function test_explicit_policy_can_ask_at_medium_risk(): void
    {
        $this->team->update(['settings' => ['action_proposal_policy' => [
            'low' => 'auto',
            'medium' => 'ask',
            'high' => 'ask',
        ]]]);
        $this->user->refresh();

        $tools = $this->wrapTools([
            $this->fakeTool('list_agents', 'list-ok'),
            $this->fakeTool('create_agent', 'create-ok'),
        ]);

        $this->assertSame('list-ok', $this->invokeFirst($tools, 'list_agents'));

        $createResult = $this->invokeFirst($tools, 'create_agent');
        $this->assertStringContainsString('proposed for human review', $createResult);
        $this->assertSame(1, ActionProposal::count());
        $this->assertSame('medium', ActionProposal::first()->risk_level);
    }

    public function test_reject_action_refuses_without_proposal(): void
    {
        $this->team->update(['settings' => ['action_proposal_policy' => [
            'low' => 'auto',
            'medium' => 'auto',
            'high' => 'reject',
        ]]]);
        $this->user->refresh();

        $tools = $this->wrapTools([
            $this->fakeTool('delete_agent', 'should-not-run'),
        ]);

        $result = $this->invokeFirst($tools, 'delete_agent');

        $this->assertStringContainsString('refused by team policy', $result);
        $this->assertStringContainsString('high-risk', $result);
        $this->assertSame(0, ActionProposal::count(), 'reject must NOT create a proposal');
    }

    public function test_invalid_policy_value_falls_back_to_auto(): void
    {
        $this->team->update(['settings' => ['action_proposal_policy' => [
            'low' => 'NONSENSE',
            'medium' => 'auto',
            'high' => 'auto',
        ]]]);
        $this->user->refresh();

        $tools = $this->wrapTools([$this->fakeTool('list_agents', 'list-ok')]);

        $this->assertSame('list-ok', $this->invokeFirst($tools, 'list_agents'));
        $this->assertSame(0, ActionProposal::count());
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
