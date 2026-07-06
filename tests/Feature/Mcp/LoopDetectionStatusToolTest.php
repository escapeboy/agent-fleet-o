<?php

namespace Tests\Feature\Mcp;

use App\Domain\Shared\Models\Team;
use App\Mcp\Tools\System\LoopDetectionStatusTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Tests\TestCase;

class LoopDetectionStatusToolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $team = Team::create([
            'name' => 'Loop Team',
            'slug' => 'loop-team',
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $team->id]);
        $this->actingAs($user);
        app()->instance('mcp.team_id', $team->id);
    }

    public function test_reports_config(): void
    {
        config([
            'loop_detection.enabled' => true,
            'loop_detection.on_trip' => 'pause',
        ]);

        $response = (new LoopDetectionStatusTool)->handle(new Request([]));
        $payload = json_decode((string) $response->content(), true);

        $this->assertFalse($response->isError());
        $this->assertTrue($payload['config']['enabled']);
        $this->assertSame('pause', $payload['config']['on_trip']);
        $this->assertNull($payload['buffered_turns']);
    }

    public function test_unknown_experiment_is_not_inspectable(): void
    {
        // A random UUID not in the caller's team resolves to null buffered_turns.
        $response = (new LoopDetectionStatusTool)->handle(new Request([
            'experiment_id' => '019f0000-0000-7000-8000-000000000000',
        ]));
        $payload = json_decode((string) $response->content(), true);

        $this->assertFalse($response->isError());
        $this->assertNull($payload['buffered_turns']);
    }
}
