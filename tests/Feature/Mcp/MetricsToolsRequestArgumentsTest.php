<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

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
        $this->team = Team::create([
            'name' => 'Metrics Args Team',
            'slug' => 'metrics-args-team',
            'owner_id' => $user->id,
            'settings' => [],
        ]);
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
