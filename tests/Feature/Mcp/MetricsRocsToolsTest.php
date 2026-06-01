<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Domain\Agent\Models\AiRun;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Models\Team;
use App\Mcp\Tools\Metrics\MetricTagValueTool;
use App\Mcp\Tools\Metrics\RocsSummaryTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Tests\TestCase;

class MetricsRocsToolsTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'ROCS Team',
            'slug' => 'rocs-team',
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $this->team->id]);
        $this->actingAs($user);
        app()->instance('mcp.team_id', $this->team->id);
    }

    public function test_rocs_summary_returns_structured_report(): void
    {
        $experiment = Experiment::factory()->create(['team_id' => $this->team->id]);
        AiRun::withoutEvents(fn () => AiRun::create([
            'team_id' => $this->team->id,
            'experiment_id' => $experiment->id,
            'purpose' => 'run',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-5',
            'prompt_snapshot' => [],
            'cost_credits' => 1250, // $1.25
            'status' => 'success',
        ]));

        $response = (new RocsSummaryTool)->handle(new Request([]));
        $data = $this->decode($response);

        $this->assertArrayHasKey('summary', $data);
        $this->assertSame(1250, $data['summary']['spend_credits']);
        $this->assertSame(1.25, $data['summary']['spend_usd']);
    }

    public function test_tag_value_writes_business_value_metric(): void
    {
        $experiment = Experiment::factory()->create(['team_id' => $this->team->id]);

        $response = (new MetricTagValueTool)->handle(new Request([
            'experiment_id' => $experiment->id,
            'value_usd' => 40.5,
            'outcome' => 'success',
        ]));
        $data = $this->decode($response);

        $this->assertSame('business_value', $data['type']);
        $this->assertSame(40.5, $data['value_usd']);
        $this->assertDatabaseHas('metrics', [
            'experiment_id' => $experiment->id,
            'type' => 'business_value',
            'team_id' => $this->team->id,
        ]);
    }

    public function test_tag_value_rejects_cross_team_experiment(): void
    {
        $other = Team::factory()->create();
        $experiment = Experiment::factory()->create(['team_id' => $other->id]);

        $response = (new MetricTagValueTool)->handle(new Request([
            'experiment_id' => $experiment->id,
            'value_usd' => 10,
        ]));
        $data = $this->decode($response);

        $this->assertArrayHasKey('error', $data);
        $this->assertDatabaseMissing('metrics', ['experiment_id' => $experiment->id]);
    }

    private function decode(Response $response): array
    {
        return json_decode((string) $response->content(), true);
    }
}
