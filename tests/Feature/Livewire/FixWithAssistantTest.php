<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Enums\StageStatus;
use App\Domain\Experiment\Enums\StageType;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Domain\Shared\Models\Team;
use App\Livewire\Shared\FixWithAssistant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FixWithAssistantTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Fix Assistant Team',
            'slug' => 'fix-assistant-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);
    }

    private function makeFailedExperiment(array $overrides = []): Experiment
    {
        return Experiment::create(array_merge([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'title' => 'Failed Test',
            'thesis' => 't',
            'status' => ExperimentStatus::BuildingFailed,
            'track' => 'growth',
            'budget_cap_credits' => 5000,
            'max_iterations' => 3,
            'current_iteration' => 1,
            'max_outbound_count' => 100,
            'outbound_count' => 0,
        ], $overrides));
    }

    public function test_renders_for_failed_experiment(): void
    {
        $experiment = $this->makeFailedExperiment();

        Livewire::test(FixWithAssistant::class, [
            'entityType' => 'experiment',
            'entityId' => $experiment->id,
        ])
            ->assertSet('eligible', true)
            ->assertSee('Diagnose');
    }

    public function test_does_not_render_for_completed_experiment(): void
    {
        $experiment = Experiment::create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'title' => 'Done',
            'thesis' => 't',
            'status' => ExperimentStatus::Completed,
            'track' => 'growth',
            'budget_cap_credits' => 5000,
            'max_iterations' => 3,
            'current_iteration' => 1,
            'max_outbound_count' => 100,
            'outbound_count' => 0,
        ]);

        Livewire::test(FixWithAssistant::class, [
            'entityType' => 'experiment',
            'entityId' => $experiment->id,
        ])
            ->assertSet('eligible', false)
            ->assertDontSee('Diagnose');
    }

    public function test_does_not_render_for_unknown_entity(): void
    {
        Livewire::test(FixWithAssistant::class, [
            'entityType' => 'experiment',
            'entityId' => '00000000-0000-0000-0000-000000000000',
        ])
            ->assertSet('eligible', false);
    }

    public function test_does_not_render_for_unsupported_entity_type(): void
    {
        $experiment = $this->makeFailedExperiment();

        Livewire::test(FixWithAssistant::class, [
            'entityType' => 'project',  // not supported in P0
            'entityId' => $experiment->id,
        ])
            ->assertSet('eligible', false);
    }

    public function test_diagnose_populates_state(): void
    {
        $experiment = $this->makeFailedExperiment();
        ExperimentStage::create([
            'team_id' => $this->team->id,
            'experiment_id' => $experiment->id,
            'stage' => StageType::Building,
            'iteration' => 1,
            'status' => StageStatus::Failed,
            'output_snapshot' => ['error' => 'PrismException: HTTP 429'],
            'completed_at' => now(),
            'duration_ms' => 0,
            'retry_count' => 0,
        ]);

        Livewire::test(FixWithAssistant::class, [
            'entityType' => 'experiment',
            'entityId' => $experiment->id,
        ])
            ->call('diagnose')
            ->assertSet('diagnosed', true)
            ->assertSet('errorMessage', '')
            ->tap(function ($component) {
                $diagnosis = $component->get('diagnosis');
                $this->assertIsArray($diagnosis);
                $this->assertSame('rate_limit', $diagnosis['root_cause']);
                $this->assertNotEmpty($diagnosis['recommended_actions']);
            });
    }

    public function test_diagnose_renders_summary_in_view(): void
    {
        $experiment = $this->makeFailedExperiment();
        ExperimentStage::create([
            'team_id' => $this->team->id,
            'experiment_id' => $experiment->id,
            'stage' => StageType::Building,
            'iteration' => 1,
            'status' => StageStatus::Failed,
            'output_snapshot' => ['error' => 'HTTP 429 rate limit'],
            'completed_at' => now(),
            'duration_ms' => 0,
            'retry_count' => 0,
        ]);

        Livewire::test(FixWithAssistant::class, [
            'entityType' => 'experiment',
            'entityId' => $experiment->id,
        ])
            ->call('diagnose')
            ->assertSee('rate-limited')  // English summary substring
            ->assertSee('Confidence');
    }

    public function test_ask_assistant_dispatches_open_assistant_event(): void
    {
        $experiment = $this->makeFailedExperiment();

        Livewire::test(FixWithAssistant::class, [
            'entityType' => 'experiment',
            'entityId' => $experiment->id,
        ])
            ->call('askAssistant', 'investigate this please')
            ->assertDispatched('open-assistant', message: 'investigate this please');
    }

    public function test_xss_safe_summary_escapes_html(): void
    {
        $experiment = $this->makeFailedExperiment();
        // Inject pseudo-script-tag string into the error so we can verify the
        // rendered HTML does not contain a literal <script> tag.
        ExperimentStage::create([
            'team_id' => $this->team->id,
            'experiment_id' => $experiment->id,
            'stage' => StageType::Building,
            'iteration' => 1,
            'status' => StageStatus::Failed,
            // Falls into 'unknown' bucket → message is the dictionary 'en' default,
            // which is safe. The XSS surface is the dictionary itself; this test
            // is a regression sentinel that proves we never echo raw error text.
            'output_snapshot' => ['error' => '<script>alert(1)</script>'],
            'completed_at' => now(),
            'duration_ms' => 0,
            'retry_count' => 0,
        ]);

        $output = (string) Livewire::test(FixWithAssistant::class, [
            'entityType' => 'experiment',
            'entityId' => $experiment->id,
        ])->call('diagnose')->html();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $output);
    }
}
