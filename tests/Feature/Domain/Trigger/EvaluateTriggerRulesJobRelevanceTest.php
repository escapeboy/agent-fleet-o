<?php

namespace Tests\Feature\Domain\Trigger;

use App\Domain\Project\Enums\ProjectStatus;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Models\Signal;
use App\Domain\Trigger\Actions\EvaluateTriggerRulesAction;
use App\Domain\Trigger\Actions\ExecuteTriggerRuleAction;
use App\Domain\Trigger\Enums\TriggerRuleStatus;
use App\Domain\Trigger\Jobs\EvaluateTriggerRulesJob;
use App\Domain\Trigger\Models\TriggerRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class EvaluateTriggerRulesJobRelevanceTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team',
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($user, ['role' => 'owner']);

        $this->project = Project::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $user->id,
            'status' => ProjectStatus::Active,
        ]);
    }

    private function makeSignal(?float $relevanceScore = null): Signal
    {
        return Signal::withoutGlobalScopes()->create([
            'team_id' => $this->team->id,
            'source_type' => 'webhook',
            'source_identifier' => 'webhook-test',
            'content_hash' => md5(uniqid()),
            'payload' => ['event' => 'test'],
            'received_at' => now(),
            'relevance_score' => $relevanceScore,
        ]);
    }

    private function makeRule(): TriggerRule
    {
        return TriggerRule::create([
            'team_id' => $this->team->id,
            'project_id' => $this->project->id,
            'name' => 'Test Rule',
            'source_type' => '*',
            'conditions' => null,
            'input_mapping' => null,
            'cooldown_seconds' => 0,
            'max_concurrent' => 0,
            'status' => TriggerRuleStatus::Active,
        ]);
    }

    private function runJobWithMockedExecute(Signal $signal, bool &$executeWasCalled): void
    {
        $evaluateAction = app(EvaluateTriggerRulesAction::class);

        $executeAction = Mockery::mock(ExecuteTriggerRuleAction::class);
        $executeAction->shouldReceive('execute')
            ->andReturnUsing(function () use (&$executeWasCalled) {
                $executeWasCalled = true;

                return null;
            });

        (new EvaluateTriggerRulesJob($signal->id))->handle($evaluateAction, $executeAction);
    }

    public function test_null_score_fails_open_and_evaluates_rules(): void
    {
        $this->team->update(['signal_relevance_threshold' => 0.5]);
        $this->makeRule();
        $signal = $this->makeSignal(null); // score not yet computed

        $executed = false;
        $this->runJobWithMockedExecute($signal, $executed);

        $this->assertTrue($executed, 'Expected rule to be executed when score is null (fail-open)');
    }

    public function test_score_above_threshold_evaluates_rules(): void
    {
        $this->team->update(['signal_relevance_threshold' => 0.5]);
        $this->makeRule();
        $signal = $this->makeSignal(0.8);

        $executed = false;
        $this->runJobWithMockedExecute($signal, $executed);

        $this->assertTrue($executed);
    }

    public function test_score_below_threshold_skips_rule_evaluation(): void
    {
        $this->team->update(['signal_relevance_threshold' => 0.5]);
        $this->makeRule();
        $signal = $this->makeSignal(0.2);

        $executed = false;
        $this->runJobWithMockedExecute($signal, $executed);

        $this->assertFalse($executed, 'Expected rule evaluation to be skipped for low-relevance signal');
    }

    public function test_no_threshold_set_evaluates_regardless_of_score(): void
    {
        $this->team->update(['signal_relevance_threshold' => null]);
        $this->makeRule();
        $signal = $this->makeSignal(0.1);

        $executed = false;
        $this->runJobWithMockedExecute($signal, $executed);

        $this->assertTrue($executed);
    }
}
