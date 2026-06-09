<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ReasoningBankEntry;
use App\Domain\Shared\Models\Team;
use App\Mcp\Tools\Experiment\ReasoningBankListTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Tests\TestCase;

class ReasoningBankListToolTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->team = Team::factory()->create(['owner_id' => $user->id]);
        $user->update(['current_team_id' => $this->team->id]);
        $this->actingAs($user);
        app()->instance('mcp.team_id', $this->team->id);
    }

    public function test_it_lists_only_the_current_teams_entries(): void
    {
        $mine = $this->makeEntry($this->team, 'Improve onboarding');

        $otherTeam = Team::factory()->create();
        $theirs = $this->makeEntry($otherTeam, 'Secret strategy');

        $result = $this->decode((new ReasoningBankListTool)->handle(new Request([])));

        $ids = array_column($result['entries'], 'id');
        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids);
        $this->assertSame(1, $result['count']);
    }

    private function makeEntry(Team $team, string $goal): ReasoningBankEntry
    {
        $experiment = Experiment::factory()->create(['team_id' => $team->id]);

        return ReasoningBankEntry::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'experiment_id' => $experiment->id,
            'goal_text' => $goal,
            'tool_sequence' => [],
            'outcome_summary' => 'Summary for '.$goal,
        ]);
    }

    private function decode(Response $response): array
    {
        return json_decode((string) $response->content(), true);
    }
}
