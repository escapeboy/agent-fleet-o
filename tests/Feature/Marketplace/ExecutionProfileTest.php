<?php

namespace Tests\Feature\Marketplace;

use App\Domain\Agent\Models\Agent;
use App\Domain\Marketplace\Actions\PublishToMarketplaceAction;
use App\Domain\Marketplace\Models\MarketplaceListing;
use App\Domain\Shared\Models\Team;
use App\Domain\Skill\Models\Skill;
use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Models\Tool;
use App\Domain\Workflow\Models\Workflow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExecutionProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    private PublishToMarketplaceAction $action;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);

        $this->action = app(PublishToMarketplaceAction::class);
    }

    public function test_skill_gets_default_execution_profile(): void
    {
        $skill = Skill::factory()->create(['team_id' => $this->team->id]);

        $listing = $this->action->execute(
            item: $skill,
            teamId: $this->team->id,
            userId: $this->user->id,
            name: 'Test Skill',
            description: 'A test skill',
        );

        $this->assertInstanceOf(MarketplaceListing::class, $listing);
        $this->assertNotNull($listing->execution_profile);
        $this->assertFalse($listing->execution_profile['requires_bash']);
        $this->assertEmpty($listing->execution_profile['requires_filesystem']);
        $this->assertFalse($listing->execution_profile['requires_browser']);
        $this->assertEquals('internal', $listing->execution_profile['data_classification']);
        $this->assertEquals('none', $listing->execution_profile['min_sandbox']);
    }

    public function test_agent_without_tools_gets_default_execution_profile(): void
    {
        $agent = Agent::factory()->create(['team_id' => $this->team->id]);

        $listing = $this->action->execute(
            item: $agent,
            teamId: $this->team->id,
            userId: $this->user->id,
            name: 'Test Agent',
            description: 'A test agent',
        );

        $this->assertFalse($listing->execution_profile['requires_bash']);
        $this->assertEmpty($listing->execution_profile['requires_filesystem']);
        $this->assertFalse($listing->execution_profile['requires_browser']);
        $this->assertEquals('none', $listing->execution_profile['min_sandbox']);
    }

    public function test_agent_with_bash_tool_sets_requires_bash_and_docker_sandbox(): void
    {
        $agent = Agent::factory()->create(['team_id' => $this->team->id]);

        $bashTool = Tool::factory()->create([
            'team_id' => $this->team->id,
            'type' => ToolType::BuiltIn,
            'transport_config' => ['kind' => 'bash'],
        ]);

        $agent->tools()->attach($bashTool, ['priority' => 1, 'overrides' => []]);

        $listing = $this->action->execute(
            item: $agent,
            teamId: $this->team->id,
            userId: $this->user->id,
            name: 'Bash Agent',
            description: 'Agent with bash tool',
        );

        $this->assertTrue($listing->execution_profile['requires_bash']);
        $this->assertEquals('docker', $listing->execution_profile['min_sandbox']);
    }

    public function test_agent_with_filesystem_tool_sets_requires_filesystem(): void
    {
        $agent = Agent::factory()->create(['team_id' => $this->team->id]);

        $fsTool = Tool::factory()->create([
            'team_id' => $this->team->id,
            'type' => ToolType::BuiltIn,
            'transport_config' => ['kind' => 'filesystem'],
        ]);

        $agent->tools()->attach($fsTool, ['priority' => 1, 'overrides' => []]);

        $listing = $this->action->execute(
            item: $agent,
            teamId: $this->team->id,
            userId: $this->user->id,
            name: 'FS Agent',
            description: 'Agent with filesystem tool',
        );

        $this->assertContains('/workspace', $listing->execution_profile['requires_filesystem']);
    }

    public function test_agent_with_browser_tool_sets_requires_browser(): void
    {
        $agent = Agent::factory()->create(['team_id' => $this->team->id]);

        $browserTool = Tool::factory()->create([
            'team_id' => $this->team->id,
            'type' => ToolType::BuiltIn,
            'transport_config' => ['kind' => 'browser'],
        ]);

        $agent->tools()->attach($browserTool, ['priority' => 1, 'overrides' => []]);

        $listing = $this->action->execute(
            item: $agent,
            teamId: $this->team->id,
            userId: $this->user->id,
            name: 'Browser Agent',
            description: 'Agent with browser tool',
        );

        $this->assertTrue($listing->execution_profile['requires_browser']);
    }

    public function test_workflow_gets_default_execution_profile(): void
    {
        $workflow = Workflow::factory()->create(['team_id' => $this->team->id]);

        $listing = $this->action->execute(
            item: $workflow,
            teamId: $this->team->id,
            userId: $this->user->id,
            name: 'Test Workflow',
            description: 'A test workflow',
        );

        $this->assertFalse($listing->execution_profile['requires_bash']);
        $this->assertEmpty($listing->execution_profile['requires_filesystem']);
        $this->assertFalse($listing->execution_profile['requires_browser']);
        $this->assertEquals('none', $listing->execution_profile['min_sandbox']);
    }
}
