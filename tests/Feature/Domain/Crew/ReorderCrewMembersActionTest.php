<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Crew;

use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Actions\ReorderCrewMembersAction;
use App\Domain\Crew\Enums\CrewMemberRole;
use App\Domain\Crew\Models\Crew;
use App\Domain\Crew\Models\CrewMember;
use App\Domain\Shared\Models\Team;
use App\Livewire\Crews\CrewDetailPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

class ReorderCrewMembersActionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Reorder Test',
            'slug' => 'reorder-test',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);
    }

    private function makeCrewWithWorkers(int $count): array
    {
        $coordinator = Agent::factory()->for($this->team)->create();
        $qa = Agent::factory()->for($this->team)->create();
        $crew = Crew::factory()->for($this->team)->create([
            'user_id' => $this->user->id,
            'coordinator_agent_id' => $coordinator->id,
            'qa_agent_id' => $qa->id,
        ]);

        $members = [];
        for ($i = 0; $i < $count; $i++) {
            $worker = Agent::factory()->for($this->team)->create();
            $members[] = CrewMember::factory()->create([
                'crew_id' => $crew->id,
                'agent_id' => $worker->id,
                'role' => CrewMemberRole::Worker,
                'sort_order' => $i,
            ]);
        }

        return ['crew' => $crew->fresh(), 'members' => $members];
    }

    public function test_reorders_workers_to_new_sort_order(): void
    {
        ['crew' => $crew, 'members' => $m] = $this->makeCrewWithWorkers(3);
        [$first, $second, $third] = $m;

        // Reverse the order: third, first, second
        app(ReorderCrewMembersAction::class)->execute($crew, [$third->id, $first->id, $second->id]);

        $this->assertSame(0, $third->fresh()->sort_order);
        $this->assertSame(1, $first->fresh()->sort_order);
        $this->assertSame(2, $second->fresh()->sort_order);
    }

    public function test_rejects_member_from_different_crew(): void
    {
        ['crew' => $crew1] = $this->makeCrewWithWorkers(1);
        ['members' => $m2] = $this->makeCrewWithWorkers(1);

        $this->expectException(InvalidArgumentException::class);
        app(ReorderCrewMembersAction::class)->execute($crew1, [$m2[0]->id]);
    }

    public function test_rejects_non_worker_role_id(): void
    {
        ['crew' => $crew] = $this->makeCrewWithWorkers(1);

        // Insert a coordinator-role member to attempt reorder against
        $coord = Agent::factory()->for($this->team)->create();
        $coordMember = CrewMember::factory()->create([
            'crew_id' => $crew->id,
            'agent_id' => $coord->id,
            'role' => CrewMemberRole::Coordinator,
            'sort_order' => 0,
        ]);

        $this->expectException(InvalidArgumentException::class);
        app(ReorderCrewMembersAction::class)->execute($crew, [$coordMember->id]);
    }

    public function test_empty_array_is_no_op(): void
    {
        ['crew' => $crew, 'members' => $m] = $this->makeCrewWithWorkers(2);

        app(ReorderCrewMembersAction::class)->execute($crew, []);

        $this->assertSame(0, $m[0]->fresh()->sort_order);
        $this->assertSame(1, $m[1]->fresh()->sort_order);
    }

    public function test_livewire_reorder_workers_via_crew_detail_page(): void
    {
        ['crew' => $crew, 'members' => $m] = $this->makeCrewWithWorkers(2);
        [$first, $second] = $m;

        Livewire::test(CrewDetailPage::class, ['crew' => $crew])
            ->call('reorderWorkers', [$second->id, $first->id]);

        $this->assertSame(0, $second->fresh()->sort_order);
        $this->assertSame(1, $first->fresh()->sort_order);
    }
}
