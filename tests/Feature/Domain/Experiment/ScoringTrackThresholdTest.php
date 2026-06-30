<?php

namespace Tests\Feature\Domain\Experiment;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Enums\ExperimentTrack;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Pipeline\RunScoringStage;
use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Models\Signal;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A low business-potential score (0.2, below the standard 0.3 threshold)
 * discards a normal experiment but must ADVANCE a Debug-track (bug-fix) run:
 * triaged bugs are work to do, not business bets to gate.
 */
class ScoringTrackThresholdTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'T',
            'slug' => 'team-'.Str::random(6),
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);

        // Skip the verification gate so the test exercises only the threshold logic.
        config(['ai_routing.verification.enabled' => false]);
    }

    private function fakeScore(float $score): void
    {
        $gateway = $this->createMock(AiGatewayInterface::class);
        $gateway->method('complete')->willReturn(new AiResponseDTO(
            content: json_encode(['score' => $score, 'reasoning' => 'low', 'recommended_track' => 'revenue']),
            parsedOutput: null,
            usage: new AiUsageDTO(promptTokens: 10, completionTokens: 10, costCredits: 1),
            provider: 'anthropic',
            model: 'claude-haiku-4-5',
            latencyMs: 10,
        ));
        $this->app->instance(AiGatewayInterface::class, $gateway);
    }

    private function makeScoringExperiment(ExperimentTrack $track): Experiment
    {
        $exp = Experiment::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'track' => $track,
            'status' => ExperimentStatus::Scoring,
            'constraints' => [],
            'current_iteration' => 0,
        ]);

        Signal::factory()->create([
            'team_id' => $this->team->id,
            'experiment_id' => $exp->id,
            'payload' => ['thesis' => $exp->thesis],
        ]);

        return $exp;
    }

    public function test_debug_track_advances_to_planning_on_low_score(): void
    {
        Queue::fake();
        $this->fakeScore(0.2);
        $exp = $this->makeScoringExperiment(ExperimentTrack::Debug);

        (new RunScoringStage($exp->id, $this->team->id))->handle();

        $this->assertSame(ExperimentStatus::Planning, $exp->fresh()->status);
    }

    public function test_non_debug_track_is_discarded_on_low_score(): void
    {
        Queue::fake();
        $this->fakeScore(0.2);
        $exp = $this->makeScoringExperiment(ExperimentTrack::Growth);

        (new RunScoringStage($exp->id, $this->team->id))->handle();

        $this->assertSame(ExperimentStatus::Discarded, $exp->fresh()->status);
    }

    public function test_explicit_constraint_threshold_still_wins_for_debug(): void
    {
        Queue::fake();
        $this->fakeScore(0.2);
        $exp = $this->makeScoringExperiment(ExperimentTrack::Debug);
        $exp->update(['constraints' => ['score_threshold' => 0.9]]);

        (new RunScoringStage($exp->id, $this->team->id))->handle();

        $this->assertSame(ExperimentStatus::Discarded, $exp->fresh()->status);
    }
}
