<?php

namespace Tests\Feature\Domain\Evaluation;

use App\Domain\ErrorMode\Models\ErrorMode;
use App\Domain\Evaluation\Actions\AppendRegressionCaseAction;
use App\Domain\Evaluation\Enums\EvaluationCaseSource;
use App\Domain\Evaluation\Enums\EvaluationCaseStatus;
use App\Domain\Evaluation\Models\EvaluationCase;
use App\Domain\Evaluation\Models\EvaluationDataset;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppendRegressionCaseActionTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $this->team = Team::create(['name' => 'AR', 'slug' => 'ar-'.uniqid(), 'owner_id' => $user->id, 'settings' => []]);
        config(['evaluation.auto_eval.enabled' => true]);
        config(['evaluation.error_mode_catalog.enabled' => false]);
    }

    public function test_creates_deferred_case_and_dataset(): void
    {
        $case = app(AppendRegressionCaseAction::class)->execute(
            teamId: $this->team->id,
            input: 'What is the refund policy?',
            failingOutput: 'I do not know.',
            errorModeLabel: 'missed_retrieval',
            source: EvaluationCaseSource::FailureLesson,
        );

        $this->assertNotNull($case);
        $this->assertSame(EvaluationCaseStatus::Deferred, $case->status);
        $this->assertSame('failure_lesson', $case->source);
        $this->assertSame('missed_retrieval', $case->error_mode);
        $this->assertSame('I do not know.', $case->metadata['failing_output_excerpt']);

        $dataset = EvaluationDataset::where('team_id', $this->team->id)->where('name', 'Production Regressions')->first();
        $this->assertNotNull($dataset);
        $this->assertSame(1, $dataset->case_count);
    }

    public function test_flag_off_returns_null(): void
    {
        config(['evaluation.auto_eval.enabled' => false]);

        $case = app(AppendRegressionCaseAction::class)->execute(
            teamId: $this->team->id,
            input: 'x',
            failingOutput: null,
            errorModeLabel: 'e',
            source: EvaluationCaseSource::FailureLesson,
        );

        $this->assertNull($case);
        $this->assertSame(0, EvaluationCase::count());
    }

    public function test_force_bypasses_flag(): void
    {
        config(['evaluation.auto_eval.enabled' => false]);

        $case = app(AppendRegressionCaseAction::class)->execute(
            teamId: $this->team->id,
            input: 'manual input',
            failingOutput: null,
            errorModeLabel: 'manual_mode',
            source: EvaluationCaseSource::Manual,
            force: true,
        );

        $this->assertNotNull($case);
    }

    public function test_idempotent_for_same_team_error_mode_input(): void
    {
        $action = app(AppendRegressionCaseAction::class);
        $args = [
            'teamId' => $this->team->id,
            'input' => 'duplicate question',
            'failingOutput' => null,
            'errorModeLabel' => 'dup_mode',
            'source' => EvaluationCaseSource::FailureLesson,
        ];

        $this->assertNotNull($action->execute(...$args));
        $this->assertNull($action->execute(...$args));
        $this->assertSame(1, EvaluationCase::count());
    }

    public function test_links_error_mode_when_catalog_enabled(): void
    {
        config(['evaluation.error_mode_catalog.enabled' => true]);

        $case = app(AppendRegressionCaseAction::class)->execute(
            teamId: $this->team->id,
            input: 'q',
            failingOutput: null,
            errorModeLabel: 'broken_format',
            source: EvaluationCaseSource::FailureLesson,
        );

        $this->assertNotNull($case->error_mode_id);
        $mode = ErrorMode::find($case->error_mode_id);
        $this->assertNotNull($mode);
        $this->assertSame(1, $mode->occurrence_count);
    }
}
