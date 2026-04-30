<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentExecution;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command;
use Tests\TestCase;

class SuggestHealthScoreThresholdsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Suggest Test',
            'slug' => 'suggest-test',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->actingAs($this->user);
        $this->agent = Agent::factory()->for($this->team)->create();
    }

    private function makeExecution(int $durationMs, int $costCredits, string $status = 'success'): void
    {
        AgentExecution::create([
            'team_id' => $this->team->id,
            'agent_id' => $this->agent->id,
            'status' => $status,
            'duration_ms' => $durationMs,
            'cost_credits' => $costCredits,
            'tool_calls_count' => 0,
            'llm_steps_count' => 1,
        ]);
    }

    public function test_returns_zero_samples_message_when_no_data(): void
    {
        $this->artisan('health-score:suggest', ['--format' => 'markdown'])
            ->expectsOutputToContain('No successful AgentExecution rows')
            ->assertExitCode(0);
    }

    public function test_excludes_failed_executions(): void
    {
        // Add a failure that would skew thresholds if not filtered
        $this->makeExecution(99000, 9999, 'failed');
        // Plus a single successful run
        $this->makeExecution(1000, 10);

        $exit = Artisan::call('health-score:suggest', [
            '--format' => 'json',
        ]);
        $report = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertSame(1, $report['sample_size_latency']);
        $this->assertSame(1000, $report['suggestion']['latency_healthy_ms']);
    }

    public function test_proposes_p50_p95_thresholds(): void
    {
        // Generate a realistic distribution: 10 fast runs + 1 slow outlier
        for ($ms = 1000; $ms <= 10000; $ms += 1000) {
            $this->makeExecution($ms, 50);
        }
        $this->makeExecution(60000, 50);

        Artisan::call('health-score:suggest', [
            '--format' => 'json',
        ]);
        $report = json_decode(Artisan::output(), true);

        // p50 of 11 sorted ≈ index 5 → 6000ms
        // p95 of 11 sorted ≈ index 9 → 10000ms (just below the outlier)
        $this->assertSame(6000, $report['suggestion']['latency_healthy_ms']);
        $this->assertSame(10000, $report['suggestion']['latency_degraded_ms']);
    }

    public function test_env_format_outputs_env_var_lines(): void
    {
        $this->makeExecution(2000, 20);
        $this->makeExecution(5000, 100);

        $this->artisan('health-score:suggest', ['--format' => 'env'])
            ->expectsOutputToContain('AGENT_SLA_LATENCY_HEALTHY_MS=')
            ->expectsOutputToContain('AGENT_SLA_COST_HEALTHY_CREDITS=')
            ->assertExitCode(0);
    }

    public function test_unknown_format_fails(): void
    {
        $this->artisan('health-score:suggest', ['--format' => 'xml'])
            ->assertExitCode(Command::INVALID);
    }
}
