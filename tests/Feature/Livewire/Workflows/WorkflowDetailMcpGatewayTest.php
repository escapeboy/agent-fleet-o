<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Workflows;

use App\Domain\Shared\Models\Team;
use App\Domain\Workflow\Models\Workflow;
use App\Livewire\Workflows\WorkflowDetailPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WorkflowDetailMcpGatewayTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    private Workflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Gateway Test Team',
            'slug' => 'gateway-test-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);

        $this->workflow = Workflow::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'name' => 'Weekly Report',
        ]);
    }

    public function test_mount_prefills_defaults(): void
    {
        Livewire::test(WorkflowDetailPage::class, ['workflow' => $this->workflow])
            ->assertSet('mcpToolName', 'weekly_report')
            ->assertSet('mcpExecutionMode', 'async')
            ->assertSee('Expose as MCP tool');
    }

    public function test_enable_saves_gateway_columns(): void
    {
        Livewire::test(WorkflowDetailPage::class, ['workflow' => $this->workflow])
            ->set('mcpToolName', 'weekly_report')
            ->set('mcpExecutionMode', 'sync')
            ->call('enableMcpGateway')
            ->assertHasNoErrors()
            ->assertSee(url('/mcp/full'));

        $this->workflow->refresh();
        $this->assertTrue($this->workflow->mcp_exposed);
        $this->assertSame('weekly_report', $this->workflow->mcp_tool_name);
        $this->assertSame('sync', $this->workflow->mcp_execution_mode);
    }

    public function test_taken_name_gives_validation_error(): void
    {
        $otherTeam = Team::factory()->create();
        Workflow::factory()->create([
            'team_id' => $otherTeam->id,
            'mcp_exposed' => true,
            'mcp_tool_name' => 'weekly_report',
        ]);

        Livewire::test(WorkflowDetailPage::class, ['workflow' => $this->workflow])
            ->set('mcpToolName', 'weekly_report')
            ->call('enableMcpGateway')
            ->assertHasErrors(['mcpToolName']);

        $this->workflow->refresh();
        $this->assertFalse($this->workflow->mcp_exposed);
        $this->assertNull($this->workflow->mcp_tool_name);
    }

    public function test_disable_clears_gateway_columns(): void
    {
        $this->workflow->update(['mcp_exposed' => true, 'mcp_tool_name' => 'weekly_report']);

        Livewire::test(WorkflowDetailPage::class, ['workflow' => $this->workflow])
            ->call('disableMcpGateway')
            ->assertHasNoErrors();

        $this->workflow->refresh();
        $this->assertFalse($this->workflow->mcp_exposed);
        $this->assertNull($this->workflow->mcp_tool_name);
    }
}
