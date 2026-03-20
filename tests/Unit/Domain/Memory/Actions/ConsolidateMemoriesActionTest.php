<?php

namespace Tests\Unit\Domain\Memory\Actions;

use App\Domain\Agent\Models\Agent;
use App\Domain\Memory\Actions\ConsolidateMemoriesAction;
use App\Domain\Memory\Models\Memory;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class ConsolidateMemoriesActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_zeros_when_consolidation_disabled(): void
    {
        config(['memory.consolidation.enabled' => false]);

        $gateway = Mockery::mock(AiGatewayInterface::class);
        $action = new ConsolidateMemoriesAction($gateway);

        $result = $action->execute(Str::uuid()->toString(), Str::uuid()->toString());

        $this->assertEquals(0, $result['clusters_formed']);
        $this->assertEquals(0, $result['memories_consolidated']);
        $this->assertEquals(0, $result['memories_created']);
    }

    public function test_returns_zeros_when_not_enough_memories(): void
    {
        config(['memory.consolidation.enabled' => true]);
        config(['memory.consolidation.min_memories_per_agent' => 50]);

        $team = Team::factory()->create();
        $agent = Agent::factory()->create(['team_id' => $team->id]);

        // Create only 5 memories — below the 50 threshold
        for ($i = 0; $i < 5; $i++) {
            Memory::withoutGlobalScopes()->create([
                'team_id' => $team->id,
                'agent_id' => $agent->id,
                'content' => "Memory #{$i}",
                'source_type' => 'execution',
                'confidence' => 1.0,
                'importance' => 0.5,
                'retrieval_count' => 0,
                'created_at' => now()->subDays(30),
            ]);
        }

        $gateway = Mockery::mock(AiGatewayInterface::class);
        $action = new ConsolidateMemoriesAction($gateway);

        $result = $action->execute($agent->id, $team->id);

        $this->assertEquals(0, $result['clusters_formed']);
        $gateway->shouldNotHaveBeenCalled();
    }
}
