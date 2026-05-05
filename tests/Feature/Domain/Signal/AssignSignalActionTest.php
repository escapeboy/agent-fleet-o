<?php

namespace Tests\Feature\Domain\Signal;

use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Actions\AssignSignalAction;
use App\Domain\Signal\Events\SignalAssigned;
use App\Domain\Signal\Models\Signal;
use App\Domain\Signal\Models\SignalComment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AssignSignalActionTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Team $team;

    private Signal $signal;

    private AssignSignalAction $action;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actor = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test '.bin2hex(random_bytes(3)),
            'slug' => 'test-'.bin2hex(random_bytes(3)),
            'owner_id' => $this->actor->id,
            'settings' => [],
        ]);
        $this->actor->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->actor, ['role' => 'owner']);

        $this->signal = Signal::factory()->create([
            'team_id' => $this->team->id,
            'experiment_id' => null,
        ]);

        $this->action = app(AssignSignalAction::class);
    }

    public function test_assigns_signal_to_team_member(): void
    {
        Event::fake();

        $assignee = User::factory()->create(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($assignee, ['role' => 'member']);

        $result = $this->action->execute($this->signal, $assignee, $this->actor);

        $this->assertEquals($assignee->id, $result->assigned_user_id);
        $this->assertNotNull($result->assigned_at);
        $this->assertDatabaseHas('signals', [
            'id' => $this->signal->id,
            'assigned_user_id' => $assignee->id,
        ]);
        Event::assertDispatched(SignalAssigned::class);
    }

    public function test_unassigns_when_assignee_is_null(): void
    {
        Event::fake();

        $assignee = User::factory()->create(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($assignee, ['role' => 'member']);
        $this->signal->update(['assigned_user_id' => $assignee->id, 'assigned_at' => now()]);

        $result = $this->action->execute($this->signal, null, $this->actor);

        $this->assertNull($result->assigned_user_id);
        $this->assertNull($result->assigned_at);
        Event::assertNotDispatched(SignalAssigned::class);
    }

    public function test_reassign_creates_system_comment(): void
    {
        Event::fake();

        $original = User::factory()->create(['name' => 'Alice', 'current_team_id' => $this->team->id]);
        $this->team->users()->attach($original, ['role' => 'member']);
        $this->signal->update(['assigned_user_id' => $original->id, 'assigned_at' => now()]);
        $this->signal->setRelation('assignedUser', $original);

        $newAssignee = User::factory()->create(['name' => 'Bob', 'current_team_id' => $this->team->id]);
        $this->team->users()->attach($newAssignee, ['role' => 'member']);

        $this->action->execute($this->signal, $newAssignee, $this->actor);

        $this->assertDatabaseHas('signal_comments', [
            'signal_id' => $this->signal->id,
            'author_type' => 'agent',
            'widget_visible' => false,
        ]);
        $comment = SignalComment::where('signal_id', $this->signal->id)
            ->where('author_type', 'agent')->first();
        $this->assertStringContainsString('Reassigned from', $comment->body);
    }

    public function test_reason_creates_human_comment(): void
    {
        Event::fake();

        $assignee = User::factory()->create(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($assignee, ['role' => 'member']);

        $this->action->execute($this->signal, $assignee, $this->actor, 'Needs your expertise');

        $this->assertDatabaseHas('signal_comments', [
            'signal_id' => $this->signal->id,
            'user_id' => $this->actor->id,
            'author_type' => 'human',
            'body' => 'Needs your expertise',
            'widget_visible' => false,
        ]);
    }

    public function test_rejects_assignee_from_different_team(): void
    {
        Event::fake();

        $otherTeam = Team::create([
            'name' => 'Other '.bin2hex(random_bytes(3)),
            'slug' => 'other-'.bin2hex(random_bytes(3)),
            'owner_id' => $this->actor->id,
            'settings' => [],
        ]);
        $outsider = User::factory()->create(['current_team_id' => $otherTeam->id]);
        $otherTeam->users()->attach($outsider, ['role' => 'member']);

        $this->expectException(\InvalidArgumentException::class);

        $this->action->execute($this->signal, $outsider, $this->actor);
    }

    public function test_unassign_is_idempotent(): void
    {
        Event::fake();

        $result = $this->action->execute($this->signal, null, $this->actor);

        $this->assertNull($result->assigned_user_id);
        Event::assertNotDispatched(SignalAssigned::class);
    }
}
