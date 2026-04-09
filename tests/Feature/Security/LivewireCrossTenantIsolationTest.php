<?php

namespace Tests\Feature\Security;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Models\Team;
use App\Domain\Skill\Models\Skill;
use App\Domain\Tool\Models\Tool;
use App\Livewire\Experiments\ExecutionLogPanel;
use App\Livewire\Experiments\WorkflowProgressPanel;
use App\Livewire\Skills\SkillLineagePanel;
use App\Livewire\Tools\ToolListPage;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression coverage for the cross-tenant IDOR cluster discovered in the
 * 2026-04-09 security review. Each panel previously called
 * ::withoutGlobalScopes() with an externally-supplied UUID, allowing any
 * authenticated user to read or mutate other tenants' records by guessing
 * IDs (or by Livewire-spoofing public component properties).
 */
class LivewireCrossTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Team $teamA;

    private Team $teamB;

    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();

        $this->teamA = Team::create([
            'name' => 'Team A',
            'slug' => 'team-a-'.uniqid(),
            'owner_id' => $ownerA->id,
            'settings' => [],
        ]);

        $this->teamB = Team::create([
            'name' => 'Team B',
            'slug' => 'team-b-'.uniqid(),
            'owner_id' => $ownerB->id,
            'settings' => [],
        ]);

        $this->userB = $ownerB;
        $this->userB->current_team_id = $this->teamB->id;
        $this->userB->save();
    }

    public function test_execution_log_panel_rejects_cross_tenant_experiment(): void
    {
        $foreignExperiment = Experiment::factory()->create(['team_id' => $this->teamA->id]);

        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($this->userB)
            ->test(ExecutionLogPanel::class, ['experimentId' => $foreignExperiment->id]);
    }

    public function test_workflow_progress_panel_rejects_cross_tenant_experiment(): void
    {
        $foreignExperiment = Experiment::factory()->create(['team_id' => $this->teamA->id]);

        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($this->userB)
            ->test(WorkflowProgressPanel::class, ['experimentId' => $foreignExperiment->id]);
    }

    public function test_skill_lineage_panel_rejects_cross_tenant_skill(): void
    {
        $foreignSkill = Skill::factory()->create(['team_id' => $this->teamA->id]);

        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($this->userB)
            ->test(SkillLineagePanel::class, ['skillId' => $foreignSkill->id]);
    }

    public function test_tool_list_page_toggle_status_rejects_cross_tenant_tool(): void
    {
        $foreignTool = Tool::factory()->create([
            'team_id' => $this->teamA->id,
            'is_platform' => false,
        ]);
        $originalStatus = $foreignTool->status;

        $this->expectException(ModelNotFoundException::class);

        try {
            Livewire::actingAs($this->userB)
                ->test(ToolListPage::class)
                ->call('toggleStatus', $foreignTool->id);
        } finally {
            $foreignTool->refresh();
            $this->assertSame(
                $originalStatus,
                $foreignTool->status,
                'Cross-tenant tool status must remain unchanged after the rejected toggle.',
            );
        }
    }
}
