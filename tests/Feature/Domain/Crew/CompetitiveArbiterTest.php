<?php

namespace Tests\Feature\Domain\Crew;

use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Enums\CrewExecutionStatus;
use App\Domain\Crew\Models\Crew;
use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Crew\Models\CrewTaskExecution;
use App\Domain\Crew\Services\CompetitiveArbiter;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompetitiveArbiterTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'T '.bin2hex(random_bytes(3)),
            'slug' => 't-'.bin2hex(random_bytes(3)),
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $this->team->id]);
    }

    private function execution(): CrewExecution
    {
        $agent = Agent::factory()->create(['team_id' => $this->team->id]);
        $crew = Crew::factory()->create([
            'team_id' => $this->team->id,
            'coordinator_agent_id' => $agent->id,
            'qa_agent_id' => $agent->id,
        ]);

        return CrewExecution::create([
            'team_id' => $this->team->id,
            'crew_id' => $crew->id,
            'goal' => 'pick best',
            'status' => CrewExecutionStatus::Executing,
            'config_snapshot' => ['settings' => ['arbitration_enabled' => true]],
        ]);
    }

    private function task(CrewExecution $e, array $output, int $sort): CrewTaskExecution
    {
        return CrewTaskExecution::create([
            'crew_execution_id' => $e->id,
            'agent_id' => null,
            'title' => 'candidate '.$sort,
            'description' => 'candidate '.$sort,
            'status' => 'completed',
            'output' => $output,
            'sort_order' => $sort,
        ]);
    }

    public function test_selects_highest_scoring_candidate(): void
    {
        $e = $this->execution();
        $this->task($e, ['error' => 'boom'], 0);                          // score 0
        $best = $this->task($e, ['content' => str_repeat('x', 700)], 1);  // long → 4
        $this->task($e, ['content' => 'short'], 2);                       // small → ~2

        $result = app(CompetitiveArbiter::class)->arbitrate($e->fresh());

        $this->assertSame(0, $result['cost']);
        $this->assertSame($best->id, $result['result']['_arbitration']['winner']['task_id']);
        $this->assertSame(3, $result['result']['_arbitration']['candidates']);
    }

    public function test_ties_break_to_earliest(): void
    {
        $e = $this->execution();
        $first = $this->task($e, ['content' => str_repeat('a', 700)], 0);
        $this->task($e, ['content' => str_repeat('b', 700)], 1);

        $result = app(CompetitiveArbiter::class)->arbitrate($e->fresh());

        $this->assertSame($first->id, $result['result']['_arbitration']['winner']['task_id']);
    }

    public function test_no_candidates_returns_null_winner(): void
    {
        $e = $this->execution();

        $result = app(CompetitiveArbiter::class)->arbitrate($e);

        $this->assertNull($result['result']['_arbitration']['winner']);
        $this->assertSame(0, $result['cost']);
    }
}
