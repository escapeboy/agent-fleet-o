<?php

namespace Tests\Feature\Livewire;

use App\Domain\Evaluation\Enums\EvaluationStatus;
use App\Domain\Evaluation\Models\EvaluationCase;
use App\Domain\Evaluation\Models\EvaluationDataset;
use App\Domain\Evaluation\Models\EvaluationRun;
use App\Domain\Evaluation\Models\EvaluationRunResult;
use App\Domain\Shared\Models\Team;
use App\Livewire\Evaluation\EvaluationCompareRunsPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class EvaluationCompareRunsPageTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'T',
            'slug' => 't-'.uniqid(),
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $this->team->id]);
        $this->actingAs($user);
    }

    private function seedRunWithCases(float $avgScore, array $perCaseScores): EvaluationRun
    {
        $dataset = EvaluationDataset::create([
            'team_id' => $this->team->id,
            'name' => 'ds-'.uniqid(),
            'case_count' => count($perCaseScores),
        ]);

        $caseIds = [];
        foreach ($perCaseScores as $i => $_) {
            $caseIds[] = EvaluationCase::create([
                'dataset_id' => $dataset->id,
                'team_id' => $this->team->id,
                'input' => "Q{$i}",
                'expected_output' => "A{$i}",
            ])->id;
        }

        $run = EvaluationRun::create([
            'team_id' => $this->team->id,
            'dataset_id' => $dataset->id,
            'status' => EvaluationStatus::Completed,
            'criteria' => ['correctness', 'relevance'],
            'aggregate_scores' => ['correctness' => $avgScore, 'relevance' => $avgScore],
            'summary' => [
                'total_cases' => count($perCaseScores),
                'pass_rate_pct' => 80.0,
                'overall_avg_score' => $avgScore,
                'target_provider' => 'anthropic',
                'target_model' => 'claude-haiku',
            ],
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        foreach ($perCaseScores as $i => $score) {
            EvaluationRunResult::create([
                'run_id' => $run->id,
                'case_id' => $caseIds[$i],
                'actual_output' => "actual {$i}",
                'score' => $score,
                'execution_time_ms' => 100,
                'created_at' => now(),
            ]);
        }

        return $run;
    }

    public function test_route_renders_for_authed_user(): void
    {
        $this->get('/evaluation/compare')->assertStatus(200);
    }

    public function test_empty_selection_shows_prompt(): void
    {
        Livewire::test(EvaluationCompareRunsPage::class)
            ->assertSee('Select two runs above');
    }

    public function test_candidate_runs_listed_in_dropdown(): void
    {
        $this->seedRunWithCases(8.0, [8, 9, 7]);
        $this->seedRunWithCases(6.0, [5, 7, 6]);

        $component = Livewire::test(EvaluationCompareRunsPage::class);
        $this->assertCount(2, $component->viewData('candidateRuns'));
    }

    public function test_comparing_two_runs_computes_overall_delta(): void
    {
        $runA = $this->seedRunWithCases(8.0, [8, 9, 7]);
        $runB = $this->seedRunWithCases(6.5, [6, 7, 7]);

        $component = Livewire::test(EvaluationCompareRunsPage::class)
            ->set('runA', $runA->id)
            ->set('runB', $runB->id);

        $this->assertEqualsWithDelta(-1.5, $component->viewData('scoreDelta'), 0.01);
    }

    public function test_per_case_diff_sorts_biggest_regression_first(): void
    {
        // runA baseline, runB has one big regression (case 0), small regression (case 1), improvement (case 2)
        $dataset = EvaluationDataset::create(['team_id' => $this->team->id, 'name' => 'shared', 'case_count' => 3]);
        $caseIds = [];
        for ($i = 0; $i < 3; $i++) {
            $caseIds[] = EvaluationCase::create([
                'dataset_id' => $dataset->id,
                'team_id' => $this->team->id,
                'input' => "Q{$i}",
                'expected_output' => "A{$i}",
            ])->id;
        }

        $runA = EvaluationRun::create([
            'team_id' => $this->team->id, 'dataset_id' => $dataset->id,
            'status' => EvaluationStatus::Completed, 'criteria' => ['correctness'],
            'summary' => ['overall_avg_score' => 8.0],
            'started_at' => now(), 'completed_at' => now(),
        ]);
        $runB = EvaluationRun::create([
            'team_id' => $this->team->id, 'dataset_id' => $dataset->id,
            'status' => EvaluationStatus::Completed, 'criteria' => ['correctness'],
            'summary' => ['overall_avg_score' => 6.0],
            'started_at' => now(), 'completed_at' => now(),
        ]);

        // Case 0: 9 → 2 (delta -7, big regression)
        // Case 1: 8 → 6 (delta -2, small)
        // Case 2: 5 → 9 (delta +4, improvement)
        foreach ([[$runA->id, $caseIds[0], 9], [$runA->id, $caseIds[1], 8], [$runA->id, $caseIds[2], 5],
            [$runB->id, $caseIds[0], 2], [$runB->id, $caseIds[1], 6], [$runB->id, $caseIds[2], 9]] as [$runId, $caseId, $score]) {
            EvaluationRunResult::create([
                'run_id' => $runId, 'case_id' => $caseId,
                'score' => $score, 'created_at' => now(),
            ]);
        }

        $component = Livewire::test(EvaluationCompareRunsPage::class)
            ->set('runA', $runA->id)
            ->set('runB', $runB->id);

        $diff = $component->viewData('perCaseDiff');
        $this->assertCount(3, $diff);
        // First row should be the biggest regression (delta -7).
        $this->assertEqualsWithDelta(-7.0, $diff[0]['delta'], 0.01);
        // Last row should be the improvement (+4).
        $this->assertEqualsWithDelta(4.0, $diff[2]['delta'], 0.01);
    }

    public function test_cross_team_runs_not_visible(): void
    {
        $otherOwner = User::factory()->create();
        $otherTeam = Team::create([
            'name' => 'other',
            'slug' => 'other-'.uniqid(),
            'owner_id' => $otherOwner->id,
            'settings' => [],
        ]);
        $otherDataset = EvaluationDataset::create(['team_id' => $otherTeam->id, 'name' => 'other-ds', 'case_count' => 0]);
        $otherRun = EvaluationRun::create([
            'team_id' => $otherTeam->id, 'dataset_id' => $otherDataset->id,
            'status' => EvaluationStatus::Completed, 'criteria' => ['correctness'],
            'summary' => ['overall_avg_score' => 9.0],
            'started_at' => now(), 'completed_at' => now(),
        ]);

        $component = Livewire::test(EvaluationCompareRunsPage::class)
            ->set('runA', $otherRun->id)
            ->set('runB', $otherRun->id);

        // Cross-team IDs cannot yield a valid comparison surface — delta null
        // is the user-visible guarantee (no score drift leaking across tenants).
        $this->assertNull($component->viewData('scoreDelta'));
    }
}
