<?php

namespace Tests\Feature\Mcp;

use App\Domain\Project\Models\Project;
use App\Domain\Shared\Models\Team;
use App\Domain\Testing\Enums\TestStrategy;
use App\Domain\Testing\Models\TestSuite;
use App\Mcp\Tools\Testing\TestSuiteListTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Tests\TestCase;

class TestSuiteListToolTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $this->team = Team::factory()->create();
        app()->instance('mcp.team_id', $this->team->id);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        return json_decode((string) $response->content(), true);
    }

    public function test_lists_only_current_team_suites(): void
    {
        TestSuite::withoutGlobalScopes()->create([
            'team_id' => $this->team->id,
            'project_id' => Project::factory()->create(['team_id' => $this->team->id])->id,
            'name' => 'Mine',
            'test_strategy' => TestStrategy::Regression,
        ]);

        $otherTeam = Team::factory()->create();
        TestSuite::withoutGlobalScopes()->create([
            'team_id' => $otherTeam->id,
            'project_id' => Project::factory()->create(['team_id' => $otherTeam->id])->id,
            'name' => 'Theirs',
            'test_strategy' => TestStrategy::Full,
        ]);

        $payload = $this->decode((new TestSuiteListTool)->handle(new Request([])));

        $this->assertSame(1, $payload['count']);
        $this->assertSame('Mine', $payload['test_suites'][0]['name']);
        $this->assertSame('regression', $payload['test_suites'][0]['strategy']);
        $this->assertSame(0, $payload['test_suites'][0]['run_count']);
    }
}
