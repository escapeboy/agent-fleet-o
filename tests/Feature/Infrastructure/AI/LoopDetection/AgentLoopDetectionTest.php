<?php

namespace Tests\Feature\Infrastructure\AI\LoopDetection;

use App\Domain\Agent\Models\AiRun;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\LoopDetection\Contracts\TurnHistoryStore;
use App\Infrastructure\AI\LoopDetection\Events\AgentLoopDetected;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Support\LoopDetection\ArrayTurnHistoryStore;
use Tests\TestCase;

class AgentLoopDetectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Deterministic, Redis-free turn history for the observer path.
        $this->app->instance(TurnHistoryStore::class, new ArrayTurnHistoryStore);

        config([
            'loop_detection.enabled' => true,
            'loop_detection.on_trip' => 'pause',
            'loop_detection.stall.repeat_threshold' => 2,
            'loop_detection.velocity.max_turns' => 100,
            'loop_detection.similarity.min_turns' => 100,
        ]);
    }

    private function makeRun(Team $team, Experiment $experiment, string $output): AiRun
    {
        return AiRun::factory()->create([
            'team_id' => $team->id,
            'experiment_id' => $experiment->id,
            'raw_output' => ['text' => $output],
        ]);
    }

    public function test_stalled_output_dispatches_loop_event(): void
    {
        Event::fake([AgentLoopDetected::class]);

        $team = Team::factory()->create();
        $experiment = Experiment::factory()->create([
            'team_id' => $team->id,
            'status' => ExperimentStatus::Executing,
        ]);

        $this->makeRun($team, $experiment, 'STUCK OUTPUT');
        $this->makeRun($team, $experiment, 'STUCK OUTPUT');

        Event::assertDispatched(AgentLoopDetected::class, function (AgentLoopDetected $event) use ($experiment) {
            return $event->run->experiment_id === $experiment->id
                && $event->signal->tripped();
        });
    }

    public function test_disabled_detection_dispatches_nothing(): void
    {
        config(['loop_detection.enabled' => false]);
        Event::fake([AgentLoopDetected::class]);

        $team = Team::factory()->create();
        $experiment = Experiment::factory()->create([
            'team_id' => $team->id,
            'status' => ExperimentStatus::Executing,
        ]);

        $this->makeRun($team, $experiment, 'STUCK OUTPUT');
        $this->makeRun($team, $experiment, 'STUCK OUTPUT');

        Event::assertNotDispatched(AgentLoopDetected::class);
    }

    public function test_loop_pauses_the_experiment(): void
    {
        $team = Team::factory()->create();
        $experiment = Experiment::factory()->create([
            'team_id' => $team->id,
            'status' => ExperimentStatus::Executing,
        ]);

        $this->makeRun($team, $experiment, 'STUCK OUTPUT');
        $this->makeRun($team, $experiment, 'STUCK OUTPUT');

        $this->assertSame(
            ExperimentStatus::Paused,
            $experiment->fresh()->status,
        );
    }

    public function test_alert_mode_records_but_does_not_pause(): void
    {
        config(['loop_detection.on_trip' => 'alert']);

        $team = Team::factory()->create();
        $experiment = Experiment::factory()->create([
            'team_id' => $team->id,
            'status' => ExperimentStatus::Executing,
        ]);

        $this->makeRun($team, $experiment, 'STUCK OUTPUT');
        $this->makeRun($team, $experiment, 'STUCK OUTPUT');

        $this->assertSame(
            ExperimentStatus::Executing,
            $experiment->fresh()->status,
        );
    }
}
