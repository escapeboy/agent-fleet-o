<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Metrics\Models\MetricAggregation;
use App\Domain\Shared\Models\Team;
use App\Mcp\Tools\System\MetricsAggregationsTool;
use App\Mcp\Tools\System\MetricsModelComparisonTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Tests\TestCase;

class MetricsToolsRequestArgumentsTest extends TestCase
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

    public function test_metrics_aggregations_accepts_filters(): void
    {
        $data = $this->decode((new MetricsAggregationsTool)->handle(new Request([
            'period' => 'daily',
            'from' => '2026-01-01',
            'to' => '2026-12-31',
            'limit' => 5,
        ])));

        $this->assertArrayHasKey('data', $data);
        $this->assertSame([], $data['data']);
    }

    public function test_metrics_aggregations_to_date_is_inclusive_and_limit_is_clamped(): void
    {
        $experiment = Experiment::factory()->create(['team_id' => $this->team->id]);

        foreach (['2026-12-30 08:00:00', '2026-12-31 15:00:00'] as $periodStart) {
            MetricAggregation::withoutGlobalScopes()->create([
                'team_id' => $this->team->id,
                'experiment_id' => $experiment->id,
                'metric_type' => 'clicks',
                'period' => 'hourly',
                'period_start' => $periodStart,
                'sum_value' => 1,
                'count' => 1,
                'avg_value' => 1,
            ]);
        }

        $inclusive = $this->decode((new MetricsAggregationsTool)->handle(new Request([
            'to' => '2026-12-31',
        ])));
        $this->assertCount(2, $inclusive['data']);

        $clamped = $this->decode((new MetricsAggregationsTool)->handle(new Request([
            'limit' => -1,
        ])));
        $this->assertCount(1, $clamped['data']);
    }

    public function test_metrics_aggregations_rejects_non_string_date(): void
    {
        $data = $this->decode((new MetricsAggregationsTool)->handle(new Request([
            'from' => ['2026-01-01'],
        ])));

        $this->assertArrayHasKey('error', $data);
    }

    public function test_metrics_aggregations_rejects_bad_from_date(): void
    {
        $data = $this->decode((new MetricsAggregationsTool)->handle(new Request([
            'from' => 'yesterday',
        ])));

        $this->assertArrayHasKey('error', $data);
    }

    public function test_metrics_model_comparison_accepts_date_range(): void
    {
        $data = $this->decode((new MetricsModelComparisonTool)->handle(new Request([
            'from' => '2026-01-01',
            'to' => '2026-12-31',
        ])));

        $this->assertArrayHasKey('totals', $data);
        $this->assertArrayHasKey('by_model', $data);
    }

    public function test_metrics_model_comparison_rejects_bad_to_date(): void
    {
        $data = $this->decode((new MetricsModelComparisonTool)->handle(new Request([
            'to' => 'tomorrow',
        ])));

        $this->assertArrayHasKey('error', $data);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $data = json_decode((string) $response->content(), true);
        $this->assertIsArray($data);

        return $data;
    }
}
