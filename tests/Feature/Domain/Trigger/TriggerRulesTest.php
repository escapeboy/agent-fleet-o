<?php

namespace Tests\Feature\Domain\Trigger;

use App\Domain\Project\Enums\ProjectRunStatus;
use App\Domain\Project\Enums\ProjectStatus;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectRun;
use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Models\Signal;
use App\Domain\Trigger\Actions\EvaluateTriggerRulesAction;
use App\Domain\Trigger\Actions\ExecuteTriggerRuleAction;
use App\Domain\Trigger\Enums\TriggerRuleStatus;
use App\Domain\Trigger\Models\TriggerRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TriggerRulesTest extends TestCase
{
    use RefreshDatabase;

    private EvaluateTriggerRulesAction $evaluator;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->evaluator = app(EvaluateTriggerRulesAction::class);

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
    }

    private function makeProject(array $attributes = []): Project
    {
        return Project::factory()->create(array_merge([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'status' => ProjectStatus::Active,
        ], $attributes));
    }

    private function makeSignal(array $payload, string $sourceType = 'webhook'): Signal
    {
        return Signal::create([
            'team_id' => $this->team->id,
            'source_type' => $sourceType,
            'source_identifier' => $sourceType.'-test',
            'content_hash' => md5(json_encode($payload).uniqid()),
            'payload' => $payload,
            'received_at' => now(),
        ]);
    }

    private function makeRule(Project $project, array $attributes = []): TriggerRule
    {
        return TriggerRule::create(array_merge([
            'team_id' => $this->team->id,
            'project_id' => $project->id,
            'name' => 'Test Rule',
            'source_type' => '*',
            'conditions' => null,
            'input_mapping' => null,
            'cooldown_seconds' => 0,
            'max_concurrent' => 0,
            'status' => TriggerRuleStatus::Active,
        ], $attributes));
    }

    public function test_matching_signal_evaluates_to_active_rule(): void
    {
        $project = $this->makeProject();
        $signal = $this->makeSignal(['event' => 'order.placed', 'amount' => 150]);
        $this->makeRule($project, [
            'conditions' => ['amount' => ['gte' => 100]],
        ]);

        $matches = $this->evaluator->execute($signal);

        $this->assertCount(1, $matches);
    }

    public function test_non_matching_condition_excludes_rule(): void
    {
        $project = $this->makeProject();
        $signal = $this->makeSignal(['amount' => 50]);
        $this->makeRule($project, [
            'conditions' => ['amount' => ['gte' => 100]],
        ]);

        $matches = $this->evaluator->execute($signal);

        $this->assertCount(0, $matches);
    }

    public function test_inactive_rule_is_skipped(): void
    {
        $project = $this->makeProject();
        $signal = $this->makeSignal(['event' => 'test']);
        $this->makeRule($project, ['status' => TriggerRuleStatus::Paused]);

        $matches = $this->evaluator->execute($signal);

        $this->assertCount(0, $matches);
    }

    public function test_source_type_filter_matches_exact_type(): void
    {
        $project = $this->makeProject();
        $signal = $this->makeSignal(['event' => 'test'], 'github');
        $this->makeRule($project, ['source_type' => 'github']);
        $this->makeRule($project, ['source_type' => 'slack']);

        $matches = $this->evaluator->execute($signal);

        $this->assertCount(1, $matches);
    }

    public function test_wildcard_source_type_matches_all(): void
    {
        $project = $this->makeProject();
        $signal = $this->makeSignal(['event' => 'test'], 'telegram');
        $this->makeRule($project, ['source_type' => '*']);

        $matches = $this->evaluator->execute($signal);

        $this->assertCount(1, $matches);
    }

    public function test_imap_source_type_matches_email_signals(): void
    {
        $project = $this->makeProject();
        $signal = $this->makeSignal(['subject' => 'Help needed'], 'email');
        $this->makeRule($project, ['source_type' => 'imap']);

        $matches = $this->evaluator->execute($signal);

        $this->assertCount(1, $matches);
    }

    public function test_email_source_type_matches_email_signals(): void
    {
        $project = $this->makeProject();
        $signal = $this->makeSignal(['subject' => 'Help needed'], 'email');
        $this->makeRule($project, ['source_type' => 'email']);

        $matches = $this->evaluator->execute($signal);

        $this->assertCount(1, $matches);
    }

    public function test_rules_from_other_team_are_not_matched(): void
    {
        $otherUser = User::factory()->create();
        $otherTeam = Team::create([
            'name' => 'Other Team',
            'slug' => 'other-team',
            'owner_id' => $otherUser->id,
            'settings' => [],
        ]);
        $otherTeam->users()->attach($otherUser, ['role' => 'owner']);

        $otherProject = Project::factory()->create([
            'team_id' => $otherTeam->id,
            'user_id' => $otherUser->id,
        ]);

        // Rule belongs to another team
        TriggerRule::create([
            'team_id' => $otherTeam->id,
            'project_id' => $otherProject->id,
            'name' => 'Other Team Rule',
            'source_type' => '*',
            'conditions' => null,
            'cooldown_seconds' => 0,
            'max_concurrent' => 0,
            'status' => TriggerRuleStatus::Active,
        ]);

        // Signal belongs to $this->team
        $signal = $this->makeSignal(['event' => 'test']);

        $matches = $this->evaluator->execute($signal);

        $this->assertCount(0, $matches);
    }

    public function test_cooldown_skips_rapid_re_triggers(): void
    {
        $project = $this->makeProject();
        $rule = $this->makeRule($project, ['cooldown_seconds' => 3600]);
        $signal = $this->makeSignal(['event' => 'test']);

        $action = app(ExecuteTriggerRuleAction::class);

        // First trigger should create a run
        $run1 = $action->execute($rule, $signal);

        // Second trigger within cooldown should be skipped
        $run2 = $action->execute($rule, $signal);

        $this->assertNotNull($run1);
        $this->assertNull($run2);
    }

    public function test_max_concurrent_blocks_when_limit_reached(): void
    {
        $project = $this->makeProject();
        $rule = $this->makeRule($project, ['max_concurrent' => 1]);
        $signal = $this->makeSignal(['event' => 'test']);

        // Create an existing running run for this project
        ProjectRun::create([
            'project_id' => $project->id,
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'status' => ProjectRunStatus::Running,
            'trigger' => 'signal',
            'input_data' => [],
            'run_number' => 1,
        ]);

        $action = app(ExecuteTriggerRuleAction::class);
        $run = $action->execute($rule, $signal);

        // Should be blocked because max_concurrent = 1 and one run is already running
        $this->assertNull($run);
    }
}
