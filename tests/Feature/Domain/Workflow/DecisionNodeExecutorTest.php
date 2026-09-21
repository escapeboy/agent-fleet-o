<?php

namespace Tests\Feature\Domain\Workflow;

use App\Domain\Budget\Enums\LedgerType;
use App\Domain\Budget\Models\CreditLedger;
use App\Domain\Decision\Contracts\DecisionModel;
use App\Domain\Decision\DTOs\ChoiceAnswer;
use App\Domain\Decision\DTOs\DecisionResult;
use App\Domain\Decision\DTOs\NoulAnswer;
use App\Domain\Decision\DTOs\ResolvedDecisionDriver;
use App\Domain\Decision\Services\DecisionDriverFactory;
use App\Domain\Decision\Services\DecisionDriverResolver;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Models\TeamProviderCredential;
use App\Domain\Workflow\Enums\WorkflowNodeType;
use App\Domain\Workflow\Executors\DecisionNodeExecutor;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowNode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class DecisionNodeExecutorTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private Experiment $experiment;

    private PlaybookStep $step;

    private ?DecisionModel $fake = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->team = Team::factory()->create();
        config()->set('decision.default', 'jev');
        config()->set('decision.drivers.jev', [
            'type' => 'system_one',
            'base_url' => 'https://api.example.test',
            'key' => 'platform-key',
            'model' => 'jev-1.13.0',
            'credential_provider' => 'typesafe',
            'credits_per_call' => 2,
        ]);

        $this->experiment = Experiment::factory()->create([
            'team_id' => $this->team->id,
            'meta' => ['input_data' => ['subject' => 'server on fire']],
        ]);
        $this->step = PlaybookStep::create([
            'experiment_id' => $this->experiment->id,
            'order' => 1,
            'status' => 'running',
        ]);
    }

    /**
     * Swap only the network-facing driver, keeping the real resolver so the
     * team-credential vs platform-key decision under test stays genuine.
     * Nothing in this suite may reach typesafe.ai.
     */
    private function fakeDriver(DecisionResult|\Throwable $response): void
    {
        $fake = new class($response) implements DecisionModel
        {
            public static string|array|null $lastState = null;

            public function __construct(private readonly DecisionResult|\Throwable $response) {}

            public function decide(array|string $state, array $questions): DecisionResult
            {
                self::$lastState = $state;

                if ($this->response instanceof \Throwable) {
                    throw $this->response;
                }

                return $this->response;
            }

            public function model(): string
            {
                return 'jev-1.13.0';
            }
        };

        $this->fake = $fake;

        $this->app->bind(DecisionDriverResolver::class, fn ($app) => new class($app->make(DecisionDriverFactory::class), $fake) extends DecisionDriverResolver
        {
            public function __construct(DecisionDriverFactory $factory, private readonly DecisionModel $fake)
            {
                parent::__construct($factory);
            }

            public function resolve(?Team $team, ?string $driverName = null): ResolvedDecisionDriver
            {
                $real = parent::resolve($team, $driverName);

                return new ResolvedDecisionDriver(
                    driver: $this->fake,
                    name: $real->name,
                    source: $real->source,
                    creditsPerCall: $real->creditsPerCall,
                );
            }
        });
    }

    private function node(array $config): WorkflowNode
    {
        $workflow = Workflow::factory()->create(['team_id' => $this->team->id]);

        return WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => WorkflowNodeType::Decision,
            'label' => 'Decide',
            'position_x' => 0,
            'position_y' => 0,
            'config' => $config,
        ]);
    }

    private function decisionResult(array $answers): DecisionResult
    {
        return new DecisionResult(answers: $answers, model: 'jev-1.13.0', inputTokens: 128, latencyMs: 310);
    }

    public function test_answers_reach_the_output_with_model_and_source(): void
    {
        $this->fakeDriver($this->decisionResult([
            'is_urgent' => new ChoiceAnswer('yes', ['yes' => 0.93, 'no' => 0.07], 0.93),
            'owner' => new ChoiceAnswer('support', ['support' => 0.8, 'sales' => 0.2], 0.8),
        ]));

        $out = app(DecisionNodeExecutor::class)->execute(
            $this->node(['questions' => ['is_urgent' => ['type' => 'choice'], 'owner' => ['type' => 'choice']]]),
            $this->step,
            $this->experiment,
        );

        $this->assertSame('yes', $out['answers']['is_urgent']['value']);
        $this->assertSame('support', $out['answers']['owner']['value']);
        $this->assertSame(0.93, $out['answers']['is_urgent']['confidence']);
        $this->assertSame('jev-1.13.0', $out['model']);
        $this->assertSame('platform', $out['decided_by']);
        $this->assertSame(310, $out['latency_ms']);
        $this->assertSame([], $out['low_confidence']);
    }

    public function test_low_confidence_answers_are_listed_without_failing_the_node(): void
    {
        $this->fakeDriver($this->decisionResult([
            'sure' => new ChoiceAnswer('yes', null, 0.95),
            'unsure' => new ChoiceAnswer('maybe', null, 0.41),
        ]));

        $out = app(DecisionNodeExecutor::class)->execute(
            $this->node([
                'questions' => ['sure' => ['type' => 'choice'], 'unsure' => ['type' => 'choice']],
                'min_confidence' => 0.7,
            ]),
            $this->step,
            $this->experiment,
        );

        $this->assertSame(['unsure'], $out['low_confidence']);
        $this->assertArrayNotHasKey('error', $out);
        $this->assertSame('maybe', $out['answers']['unsure']['value']);
    }

    public function test_a_null_confidence_counts_as_low_not_as_passing(): void
    {
        // NoulAnswer::confidence() is null by construction. Treating null as
        // "confident enough" would route every score-style answer straight past
        // the escalation edge.
        $this->fakeDriver($this->decisionResult(['risk' => new NoulAnswer(0.62)]));

        $out = app(DecisionNodeExecutor::class)->execute(
            $this->node(['questions' => ['risk' => ['type' => 'noul']], 'min_confidence' => 0.7]),
            $this->step,
            $this->experiment,
        );

        $this->assertSame(['risk'], $out['low_confidence']);
        $this->assertSame(0.62, $out['answers']['risk']['value']);
    }

    public function test_without_min_confidence_nothing_is_flagged(): void
    {
        $this->fakeDriver($this->decisionResult(['risk' => new NoulAnswer(0.1)]));

        $out = app(DecisionNodeExecutor::class)->execute(
            $this->node(['questions' => ['risk' => ['type' => 'noul']]]),
            $this->step,
            $this->experiment,
        );

        $this->assertSame([], $out['low_confidence']);
    }

    public function test_a_team_credential_shows_up_as_decided_by_team(): void
    {
        TeamProviderCredential::create([
            'team_id' => $this->team->id,
            'provider' => 'typesafe',
            'name' => 'Typesafe',
            'credentials' => ['api_key' => 'team-key'],
            'is_active' => true,
        ]);
        $this->fakeDriver($this->decisionResult(['q' => new ChoiceAnswer('yes', null, 0.9)]));

        $out = app(DecisionNodeExecutor::class)->execute(
            $this->node(['questions' => ['q' => ['type' => 'choice']]]),
            $this->step,
            $this->experiment,
        );

        $this->assertSame('team', $out['decided_by']);
    }

    private function seedCredits(int $amount = 100): void
    {
        $user = User::factory()->create(['current_team_id' => $this->team->id]);
        $this->experiment->update(['user_id' => $user->id]);

        CreditLedger::create([
            'team_id' => $this->team->id,
            'user_id' => $user->id,
            'type' => LedgerType::Purchase->value,
            'amount' => $amount,
            'balance_after' => $amount,
            'description' => 'seed',
        ]);
    }

    private function spend(): int
    {
        return (int) CreditLedger::withoutGlobalScopes()
            ->where('team_id', $this->team->id)
            ->whereNot('type', LedgerType::Purchase->value)
            ->sum('amount');
    }

    public function test_a_platform_key_call_debits_credits_per_call(): void
    {
        $this->seedCredits();
        $this->fakeDriver($this->decisionResult(['q' => new ChoiceAnswer('yes', null, 0.9)]));

        $out = app(DecisionNodeExecutor::class)->execute(
            $this->node(['questions' => ['q' => ['type' => 'choice']]]),
            $this->step,
            $this->experiment->fresh(),
        );

        $this->assertSame('platform', $out['decided_by']);
        $this->assertSame(-2, $this->spend());
    }

    public function test_a_team_key_call_is_not_charged(): void
    {
        $this->seedCredits();
        TeamProviderCredential::create([
            'team_id' => $this->team->id,
            'provider' => 'typesafe',
            'name' => 'Typesafe',
            'credentials' => ['api_key' => 'team-key'],
            'is_active' => true,
        ]);
        $this->fakeDriver($this->decisionResult(['q' => new ChoiceAnswer('yes', null, 0.9)]));

        $out = app(DecisionNodeExecutor::class)->execute(
            $this->node(['questions' => ['q' => ['type' => 'choice']]]),
            $this->step,
            $this->experiment->fresh(),
        );

        $this->assertSame('team', $out['decided_by']);
        $this->assertSame(0, $this->spend());
    }

    public function test_a_driver_failure_releases_the_whole_reservation(): void
    {
        $this->seedCredits();
        $this->fakeDriver(new RuntimeException('decision endpoint down'));

        $out = app(DecisionNodeExecutor::class)->execute(
            $this->node(['questions' => ['q' => ['type' => 'choice']]]),
            $this->step,
            $this->experiment->fresh(),
        );

        $this->assertArrayHasKey('error', $out);
        $this->assertSame(0, $this->spend());
    }

    public function test_missing_questions_is_a_config_error_before_any_call(): void
    {
        $out = app(DecisionNodeExecutor::class)->execute(
            $this->node(['state' => '{{input.subject}}']),
            $this->step,
            $this->experiment,
        );

        $this->assertArrayHasKey('error', $out);
        $this->assertStringContainsString('questions', $out['error']);
        $this->assertArrayNotHasKey('answers', $out);
    }

    public function test_a_driver_failure_surfaces_as_an_error_with_no_partial_output(): void
    {
        $this->fakeDriver(new RuntimeException('typesafe timed out'));

        $out = app(DecisionNodeExecutor::class)->execute(
            $this->node(['questions' => ['q' => ['type' => 'choice']]]),
            $this->step,
            $this->experiment,
        );

        $this->assertSame('typesafe timed out', $out['error']);
        $this->assertArrayNotHasKey('answers', $out);
    }

    public function test_state_is_interpolated_from_workflow_context(): void
    {
        $this->fakeDriver($this->decisionResult(['q' => new ChoiceAnswer('yes', null, 0.9)]));

        app(DecisionNodeExecutor::class)->execute(
            $this->node(['questions' => ['q' => ['type' => 'choice']], 'state' => 'Subject: {{input.subject}}']),
            $this->step,
            $this->experiment,
        );

        $this->assertSame('Subject: server on fire', $this->fake::$lastState);
    }
}
