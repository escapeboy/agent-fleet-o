<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Enums\StageStatus;
use App\Domain\Experiment\Enums\StageType;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Domain\Shared\Models\Team;
use App\Livewire\Shared\FixWithAssistant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Concerns\MakesFailedExperiments;
use Tests\TestCase;

/**
 * End-to-end interaction test for the customer self-service troubleshooting
 * surface. Covers the full happy-path flow a real customer would take:
 *
 *   1. Navigate to a failed experiment
 *   2. See the Diagnose card eligible
 *   3. Click Diagnose → diagnosis populates
 *   4. Click safe-tier "Retry now" recovery action → tool executes
 *   5. Observe state transitions (notify event, diagnosis cleared,
 *      experiment status changed, audit entry written)
 *
 * Without a real browser, this exercises the same Blade/Livewire/Action
 * pipeline that runs in production and asserts the cross-cutting
 * outcomes in one go.
 */
class SelfServiceEndToEndTest extends TestCase
{
    use MakesFailedExperiments;
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'E2E Self-Service Team',
            'slug' => 'e2e-self-service',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);
    }

    public function test_full_diagnose_then_retry_flow(): void
    {
        // The retry path triggers ExperimentTransitioned which dispatches
        // DispatchNextStageJob. Fake the queue so the post-transition stage
        // job doesn't try to call the LLM gateway during the test.
        Queue::fake();

        // 1. Failed experiment with a known dictionary error
        $experiment = $this->makeFailedExperiment([
            'status' => ExperimentStatus::ScoringFailed,
        ]);
        ExperimentStage::create([
            'team_id' => $this->team->id,
            'experiment_id' => $experiment->id,
            'stage' => StageType::Scoring,
            'iteration' => 1,
            'status' => StageStatus::Failed,
            'output_snapshot' => ['error' => 'PrismException: HTTP 429 rate limit exceeded'],
            'completed_at' => now(),
            'duration_ms' => 0,
            'retry_count' => 0,
        ]);

        // 2. Mount the FixWithAssistant component (this is what the experiment
        //    detail page does via <x-fix-with-assistant>)
        $component = Livewire::test(FixWithAssistant::class, [
            'entityType' => 'experiment',
            'entityId' => $experiment->id,
        ])
            // Eligible because experiment is in a failed state
            ->assertSet('eligible', true)
            ->assertSee('Diagnose');

        // 3. Click Diagnose
        $component->call('diagnose')
            ->assertSet('diagnosed', true)
            ->assertSet('errorMessage', '')
            ->tap(function ($c) {
                $diagnosis = $c->get('diagnosis');
                $this->assertIsArray($diagnosis);
                $this->assertSame('rate_limit', $diagnosis['root_cause']);
                $this->assertNotEmpty($diagnosis['recommended_actions']);
            });

        $this->assertDatabaseHas('audit_entries', [
            'event' => 'experiment.diagnose',
            'subject_id' => $experiment->id,
            'team_id' => $this->team->id,
        ]);

        // 4. Click safe-tier retry — bypasses the assistant and invokes
        //    ExperimentRetryTool directly via executeRecoveryAction
        $component->call('executeRecoveryAction', 'experiment_retry', [
            'experiment_id' => $experiment->id,
        ])
            // No error was set
            ->assertSet('errorMessage', '')
            // Notify event was dispatched (downstream Livewire toast)
            ->assertDispatched('notify')
            // Diagnosis cleared so the customer sees a fresh slate
            ->assertSet('diagnosis', null);

        // 5. Experiment was transitioned out of ScoringFailed (RetryExperimentAction
        //    moves ScoringFailed → Scoring synchronously)
        $experiment->refresh();
        $this->assertSame(
            ExperimentStatus::Scoring,
            $experiment->status,
            'Retry should have transitioned ScoringFailed → Scoring',
        );

        // After retry, the eligibility re-check no longer matches (Scoring is not failed/paused)
        $component->assertSet('eligible', false);
    }

    public function test_diagnose_falls_back_to_unknown_for_novel_error(): void
    {
        $experiment = $this->makeFailedExperiment();
        ExperimentStage::create([
            'team_id' => $this->team->id,
            'experiment_id' => $experiment->id,
            'stage' => StageType::Building,
            'iteration' => 1,
            'status' => StageStatus::Failed,
            'output_snapshot' => ['error' => 'TotallyNovelException: never seen this before'],
            'completed_at' => now(),
            'duration_ms' => 0,
            'retry_count' => 0,
        ]);

        Livewire::test(FixWithAssistant::class, [
            'entityType' => 'experiment',
            'entityId' => $experiment->id,
        ])
            ->call('diagnose')
            ->tap(function ($c) {
                $diagnosis = $c->get('diagnosis');
                $this->assertIsArray($diagnosis);
                // Falls into the 'unknown' bucket but still surfaces actions
                $this->assertNotEmpty($diagnosis['recommended_actions']);
                $this->assertFalse($diagnosis['matched_dictionary'] ?? true);
            });
    }
}
