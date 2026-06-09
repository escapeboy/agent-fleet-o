<?php

namespace Tests\Feature\Livewire;

use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Shared\Models\Team;
use App\Livewire\Approvals\HumanTaskForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class HumanTaskSlaConfigTest extends TestCase
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

        $this->actingAs($this->user);
    }

    private function makeTask(?Team $team = null): ApprovalRequest
    {
        $team ??= $this->team;

        return ApprovalRequest::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'status' => ApprovalStatus::Pending,
            'form_schema' => ['properties' => ['note' => ['type' => 'string']]],
            'workflow_node_id' => null,
            'context' => ['node_label' => 'Review'],
        ]);
    }

    public function test_save_sets_sla_hours_and_escalation_chain(): void
    {
        $second = User::factory()->create();
        $this->team->users()->attach($second, ['role' => 'member']);

        $task = $this->makeTask();

        Livewire::test(HumanTaskForm::class, ['task' => $task])
            ->set('slaHours', 12)
            ->set('escalationChain', [$this->user->id, $second->id])
            ->call('saveEscalationConfig')
            ->assertHasNoErrors();

        $task->refresh();
        $this->assertSame(12, $task->context['sla_hours']);
        $this->assertSame([$this->user->id, $second->id], $task->escalation_chain);
    }

    public function test_escalation_chain_rejects_user_from_other_team(): void
    {
        $otherOwner = User::factory()->create();
        $otherTeam = Team::create([
            'name' => 'Other',
            'slug' => 'other-'.bin2hex(random_bytes(3)),
            'owner_id' => $otherOwner->id,
            'settings' => [],
        ]);
        $otherTeam->users()->attach($otherOwner, ['role' => 'owner']);

        $task = $this->makeTask();

        Livewire::test(HumanTaskForm::class, ['task' => $task])
            ->set('escalationChain', [$otherOwner->id])
            ->call('saveEscalationConfig')
            ->assertHasErrors('escalationChain.0');

        $task->refresh();
        $this->assertNull($task->escalation_chain);
    }

    public function test_unauthorized_user_cannot_save_escalation_config(): void
    {
        Gate::define('edit-content', fn () => false);

        $task = $this->makeTask();

        Livewire::test(HumanTaskForm::class, ['task' => $task])
            ->set('slaHours', 6)
            ->call('saveEscalationConfig')
            ->assertForbidden();
    }
}
