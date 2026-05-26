<?php

namespace Tests\Feature\Domain\Memory;

use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentExecution;
use App\Domain\Memory\Models\Memory;
use App\Domain\Memory\Services\MemoryNudgeInjector;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MemoryNudgeInjectorTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private Agent $agent;

    private MemoryNudgeInjector $injector;

    protected function setUp(): void
    {
        parent::setUp();
        config(['memory.nudge.execution_threshold' => 3]);

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Nudge Team',
            'slug' => 'nudge-team',
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $this->agent = Agent::factory()->for($this->team)->create();
        $this->injector = new MemoryNudgeInjector;
    }

    private function enableNudge(): void
    {
        $this->team->update(['settings' => ['memory_nudge_enabled' => true]]);
        $this->agent->refresh();
    }

    private function makeExecutions(int $count, ?Carbon $at = null): void
    {
        for ($i = 0; $i < $count; $i++) {
            $execution = AgentExecution::create([
                'agent_id' => $this->agent->id,
                'team_id' => $this->team->id,
                'status' => 'completed',
            ]);

            if ($at !== null) {
                $execution->created_at = $at;
                $execution->save();
            }
        }
    }

    public function test_returns_null_when_team_has_not_opted_in(): void
    {
        $this->makeExecutions(10);

        $this->assertNull($this->injector->nudgeFor($this->agent));
    }

    public function test_returns_null_below_threshold(): void
    {
        $this->enableNudge();
        $this->makeExecutions(2);

        $this->assertNull($this->injector->nudgeFor($this->agent));
    }

    public function test_returns_nudge_at_threshold(): void
    {
        $this->enableNudge();
        $this->makeExecutions(3);

        $this->assertNotNull($this->injector->nudgeFor($this->agent));
    }

    public function test_recent_memory_resets_the_counter(): void
    {
        $this->enableNudge();

        // 5 executions two days ago, then a memory recorded one day ago.
        $this->makeExecutions(5, now()->subDays(2));
        $memory = Memory::create([
            'team_id' => $this->team->id,
            'agent_id' => $this->agent->id,
            'content' => 'A durable learning.',
            'source_type' => 'manual',
            'importance' => 0.5,
        ]);
        $memory->created_at = now()->subDay();
        $memory->save();

        // Only pre-memory activity exists → no nudge.
        $this->assertNull($this->injector->nudgeFor($this->agent));

        // New activity after the memory crosses the threshold again.
        $this->makeExecutions(3);
        $this->assertNotNull($this->injector->nudgeFor($this->agent));
    }
}
