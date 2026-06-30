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
 * RunScoringStage resolves its rubric (scoring question + threshold) per
 * experiment via: constraints.score_threshold → signal source_type → track →
 * default. Different signal types are judged by the right criteria, and a
 * triaged bug is no longer discarded under a business-potential lens.
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

        config(['ai_routing.verification.enabled' => false]);
    }

    private function fakeScore(float $score): void
    {
        $gateway = $this->createMock(AiGatewayInterface::class);
        $gateway->method('complete')->willReturn(new AiResponseDTO(
            content: json_encode(['score' => $score, 'reasoning' => 'x', 'recommended_track' => 'high']),
            parsedOutput: null,
            usage: new AiUsageDTO(promptTokens: 10, completionTokens: 10, costCredits: 1),
            provider: 'anthropic',
            model: 'claude-haiku-4-5',
            latencyMs: 10,
        ));
        $this->app->instance(AiGatewayInterface::class, $gateway);
    }

    private function makeScoringExperiment(ExperimentTrack $track, string $sourceType = 'manual'): Experiment
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
            'source_type' => $sourceType,
            'payload' => ['thesis' => $exp->thesis],
        ]);

        return $exp;
    }

    private function runScoring(Experiment $exp): ExperimentStatus
    {
        Queue::fake();
        (new RunScoringStage($exp->id, $this->team->id))->handle();

        return $exp->fresh()->status;
    }

    public function test_bug_report_source_routes_to_severity_rubric_and_beats_track(): void
    {
        // source=bug_report → debug/severity (threshold 0.2); track=Growth would
        // be default (0.3). Score 0.25 advances only if the source rubric wins.
        $this->fakeScore(0.25);
        $exp = $this->makeScoringExperiment(ExperimentTrack::Growth, 'bug_report');

        $this->assertSame(ExperimentStatus::Planning, $this->runScoring($exp));
    }

    public function test_debug_track_advances_on_real_bug_score(): void
    {
        $this->fakeScore(0.5);
        $exp = $this->makeScoringExperiment(ExperimentTrack::Debug);

        $this->assertSame(ExperimentStatus::Planning, $this->runScoring($exp));
    }

    public function test_debug_filters_noise_below_severity_threshold(): void
    {
        $this->fakeScore(0.1);
        $exp = $this->makeScoringExperiment(ExperimentTrack::Debug);

        $this->assertSame(ExperimentStatus::Discarded, $this->runScoring($exp));
    }

    public function test_default_business_rubric_discards_low_score(): void
    {
        $this->fakeScore(0.25);
        $exp = $this->makeScoringExperiment(ExperimentTrack::Growth, 'manual');

        $this->assertSame(ExperimentStatus::Discarded, $this->runScoring($exp));
    }

    public function test_explicit_constraint_threshold_overrides_rubric(): void
    {
        $this->fakeScore(0.5);
        $exp = $this->makeScoringExperiment(ExperimentTrack::Debug);
        $exp->update(['constraints' => ['score_threshold' => 0.9]]);

        $this->assertSame(ExperimentStatus::Discarded, $this->runScoring($exp));
    }

    public function test_falls_back_to_default_when_rubrics_config_empty(): void
    {
        config(['experiments.scoring_rubrics' => [], 'experiments.scoring_rubric_by_source' => []]);
        $this->fakeScore(0.5);
        $exp = $this->makeScoringExperiment(ExperimentTrack::Growth, 'manual');

        // Fallback business prompt + 0.3: 0.5 >= 0.3 → advances, no crash.
        $this->assertSame(ExperimentStatus::Planning, $this->runScoring($exp));
    }
}
