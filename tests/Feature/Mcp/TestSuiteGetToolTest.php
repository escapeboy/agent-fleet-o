<?php

namespace Tests\Feature\Mcp;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Models\Team;
use App\Domain\Testing\Enums\TestStatus;
use App\Domain\Testing\Enums\TestStrategy;
use App\Domain\Testing\Models\TestRun;
use App\Domain\Testing\Models\TestSuite;
use App\Mcp\Tools\Testing\TestSuiteGetTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Tests\TestCase;

class TestSuiteGetToolTest extends TestCase
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

    public function test_returns_suite_with_its_runs(): void
    {
        $suite = TestSuite::withoutGlobalScopes()->create([
            'team_id' => $this->team->id,
            'project_id' => Project::factory()->create(['team_id' => $this->team->id])->id,
            'name' => 'Owned',
            'test_strategy' => TestStrategy::Regression,
        ]);

        $experiment = Experiment::factory()->create(['team_id' => $this->team->id]);

        TestRun::create([
            'test_suite_id' => $suite->id,
            'experiment_id' => $experiment->id,
            'status' => TestStatus::Passed,
            'score' => 0.91,
            'started_at' => now(),
        ]);

        $payload = $this->decode((new TestSuiteGetTool)->handle(
            new Request(['test_suite_id' => $suite->id]),
        ));

        $this->assertSame('Owned', $payload['name']);
        $this->assertSame(1, $payload['run_count']);
        $this->assertSame('passed', $payload['runs'][0]['status']);
        $this->assertSame(0.91, $payload['runs'][0]['score']);
    }

    public function test_rejects_cross_tenant_suite(): void
    {
        $otherTeam = Team::factory()->create();
        $foreign = TestSuite::withoutGlobalScopes()->create([
            'team_id' => $otherTeam->id,
            'project_id' => Project::factory()->create(['team_id' => $otherTeam->id])->id,
            'name' => 'Foreign',
            'test_strategy' => TestStrategy::Full,
        ]);

        $response = (new TestSuiteGetTool)->handle(
            new Request(['test_suite_id' => $foreign->id]),
        );

        $this->assertSame('NOT_FOUND', $this->decode($response)['error']['code']);
    }
}
