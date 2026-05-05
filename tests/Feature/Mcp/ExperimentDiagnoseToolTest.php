<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Domain\Agent\Models\Agent;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Enums\StageStatus;
use App\Domain\Experiment\Enums\StageType;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Domain\Experiment\Models\WorklogEntry;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Models\CircuitBreakerState;
use App\Mcp\Tools\Experiment\ExperimentDiagnoseTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Tests\TestCase;

class ExperimentDiagnoseToolTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Diagnose Test Team',
            'slug' => 'diagnose-test-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);

        app()->instance('mcp.team_id', $this->team->id);
    }

    private function decode(Response $response): array
    {
        return json_decode((string) $response->content(), true);
    }

    public function test_diagnoses_failed_experiment_with_rate_limit_error(): void
    {
        $experiment = Experiment::factory()
            ->for($this->team)
            ->for($this->user)
            ->create(['status' => ExperimentStatus::BuildingFailed]);

        ExperimentStage::factory()
            ->for($this->team)
            ->for($experiment)
            ->create([
                'stage' => StageType::Building,
                'status' => StageStatus::Failed,
                'output_snapshot' => [
                    'error' => 'PrismException: HTTP 429 — rate limit exceeded',
                ],
                'completed_at' => now(),
            ]);

        $tool = new ExperimentDiagnoseTool;
        $response = $tool->handle(new Request(['experiment_id' => $experiment->id]));

        $this->assertFalse($response->isError());
        $payload = $this->decode($response);

        $this->assertSame($experiment->id, $payload['experiment_id']);
        $this->assertSame('rate_limit', $payload['root_cause']);
        $this->assertTrue($payload['retryable']);
        $this->assertGreaterThan(0.8, $payload['confidence']);
        $this->assertNotEmpty($payload['recommended_actions']);
        $this->assertSame('experiment_retry', $payload['recommended_actions'][0]['target']);
        // Placeholder substituted in tool params
        $this->assertSame($experiment->id, $payload['recommended_actions'][0]['params']['experiment_id']);

        // Evidence array contains the failed stage
        $this->assertNotEmpty($payload['evidence']);
        $stageEvidence = collect($payload['evidence'])->firstWhere('kind', 'stage_failure');
        $this->assertNotNull($stageEvidence);
        $this->assertSame('building', $stageEvidence['stage']);
    }

    public function test_diagnoses_completed_experiment_returns_no_failure(): void
    {
        $experiment = Experiment::factory()
            ->for($this->team)
            ->for($this->user)
            ->completed()
            ->create();

        $tool = new ExperimentDiagnoseTool;
        $response = $tool->handle(new Request(['experiment_id' => $experiment->id]));

        $payload = $this->decode($response);
        $this->assertSame('no_failure_detected', $payload['root_cause']);
        $this->assertEmpty($payload['recommended_actions']);
        $this->assertEqualsWithDelta(1.0, $payload['confidence'], 0.001);
    }

    public function test_cross_tenant_access_returns_not_found(): void
    {
        $otherUser = User::factory()->create();
        $otherTeam = Team::create([
            'name' => 'Other Team',
            'slug' => 'other-team-diagnose',
            'owner_id' => $otherUser->id,
            'settings' => [],
        ]);

        $experiment = Experiment::factory()
            ->for($otherTeam)
            ->for($otherUser)
            ->create(['status' => ExperimentStatus::BuildingFailed]);

        $tool = new ExperimentDiagnoseTool;
        $response = $tool->handle(new Request(['experiment_id' => $experiment->id]));

        $this->assertTrue($response->isError());
        $payload = json_decode((string) $response->content(), true);
        $this->assertSame('NOT_FOUND', $payload['error']['code']);
    }

    public function test_missing_team_returns_permission_denied(): void
    {
        app()->forgetInstance('mcp.team_id');
        // Avoid fallthrough to auth()->user()
        Auth::logout();

        $experiment = Experiment::factory()
            ->for($this->team)
            ->for($this->user)
            ->create(['status' => ExperimentStatus::BuildingFailed]);

        $tool = new ExperimentDiagnoseTool;
        $response = $tool->handle(new Request(['experiment_id' => $experiment->id]));

        $this->assertTrue($response->isError());
        $payload = json_decode((string) $response->content(), true);
        $this->assertSame('PERMISSION_DENIED', $payload['error']['code']);
    }

    public function test_circuit_breaker_open_added_to_evidence(): void
    {
        $agent = Agent::factory()->for($this->team)->create();

        $experiment = Experiment::factory()
            ->for($this->team)
            ->for($this->user)
            ->create([
                'agent_id' => $agent->id,
                'status' => ExperimentStatus::BuildingFailed,
            ]);

        ExperimentStage::factory()
            ->for($this->team)
            ->for($experiment)
            ->create([
                'stage' => StageType::Building,
                'status' => StageStatus::Failed,
                'output_snapshot' => ['error' => 'PrismException: HTTP 503'],
                'completed_at' => now(),
            ]);

        CircuitBreakerState::create([
            'team_id' => $this->team->id,
            'agent_id' => $agent->id,
            'state' => 'open',
            'failure_count' => 5,
            'success_count' => 0,
            'cooldown_seconds' => 60,
            'failure_threshold' => 5,
            'opened_at' => now(),
        ]);

        $tool = new ExperimentDiagnoseTool;
        $response = $tool->handle(new Request(['experiment_id' => $experiment->id]));

        $payload = $this->decode($response);
        $cbEvidence = collect($payload['evidence'])->firstWhere('kind', 'circuit_breaker');

        $this->assertNotNull($cbEvidence);
        $this->assertSame('open', $cbEvidence['state']);
        $this->assertSame(5, $cbEvidence['failure_count']);
    }

    public function test_worklog_entries_included_as_evidence(): void
    {
        $experiment = Experiment::factory()
            ->for($this->team)
            ->for($this->user)
            ->create(['status' => ExperimentStatus::BuildingFailed]);

        WorklogEntry::create([
            'team_id' => $this->team->id,
            'workloggable_type' => Experiment::class,
            'workloggable_id' => $experiment->id,
            'type' => 'observation',
            'content' => 'Agent attempted to call missing tool: github_pr_create',
            'metadata' => [],
        ]);

        $tool = new ExperimentDiagnoseTool;
        $response = $tool->handle(new Request(['experiment_id' => $experiment->id]));

        $payload = $this->decode($response);
        $worklog = collect($payload['evidence'])->firstWhere('kind', 'worklog_entry');

        $this->assertNotNull($worklog);
        $this->assertSame('observation', $worklog['type']);
        $this->assertStringContainsString('github_pr_create', $worklog['content']);
    }

    public function test_no_recorded_error_falls_back_to_unknown_root_cause(): void
    {
        $experiment = Experiment::factory()
            ->for($this->team)
            ->for($this->user)
            ->create([
                'status' => ExperimentStatus::BuildingFailed,
                'meta' => [],
            ]);

        $tool = new ExperimentDiagnoseTool;
        $response = $tool->handle(new Request(['experiment_id' => $experiment->id]));

        $payload = $this->decode($response);
        $this->assertSame('unknown_failure', $payload['root_cause']);
        // Generic 'unknown' bucket still surfaces a retry + assistant action
        $this->assertNotEmpty($payload['recommended_actions']);
    }

    public function test_diagnose_writes_audit_entry(): void
    {
        $experiment = Experiment::factory()
            ->for($this->team)
            ->for($this->user)
            ->create(['status' => ExperimentStatus::BuildingFailed]);

        ExperimentStage::factory()
            ->for($this->team)
            ->for($experiment)
            ->create([
                'stage' => StageType::Building,
                'status' => StageStatus::Failed,
                'output_snapshot' => ['error' => 'PrismException: HTTP 429'],
                'completed_at' => now(),
            ]);

        $tool = new ExperimentDiagnoseTool;
        $tool->handle(new Request(['experiment_id' => $experiment->id]));

        $this->assertDatabaseHas('audit_entries', [
            'event' => 'experiment.diagnose',
            'subject_id' => $experiment->id,
            'team_id' => $this->team->id,
        ]);
    }

    public function test_locale_parameter_changes_message_language(): void
    {
        $experiment = Experiment::factory()
            ->for($this->team)
            ->for($this->user)
            ->create(['status' => ExperimentStatus::BuildingFailed]);

        ExperimentStage::factory()
            ->for($this->team)
            ->for($experiment)
            ->create([
                'stage' => StageType::Building,
                'status' => StageStatus::Failed,
                'output_snapshot' => ['error' => 'HTTP 429 rate limit'],
                'completed_at' => now(),
            ]);

        $tool = new ExperimentDiagnoseTool;
        $response = $tool->handle(new Request([
            'experiment_id' => $experiment->id,
            'locale' => 'bg',
        ]));

        $payload = $this->decode($response);
        $this->assertStringContainsString('доставчик', $payload['summary']);
    }
}
