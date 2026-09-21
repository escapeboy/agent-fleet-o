<?php

namespace Tests\Feature\Domain\Skill;

use App\Domain\Budget\Enums\LedgerType;
use App\Domain\Budget\Models\CreditLedger;
use App\Domain\Decision\Contracts\DecisionModel;
use App\Domain\Decision\DTOs\ChoiceAnswer;
use App\Domain\Decision\DTOs\DecisionResult;
use App\Domain\Decision\DTOs\ResolvedDecisionDriver;
use App\Domain\Decision\Services\DecisionDriverFactory;
use App\Domain\Decision\Services\DecisionDriverResolver;
use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Models\TeamProviderCredential;
use App\Domain\Skill\Actions\ExecuteDecisionSkillAction;
use App\Domain\Skill\Actions\ExecuteSkillAction;
use App\Domain\Skill\Enums\SkillType;
use App\Domain\Skill\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExecuteDecisionSkillActionTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

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
    }

    private function fakeDriver(DecisionResult|\Throwable $response): void
    {
        $fake = new class($response) implements DecisionModel
        {
            public function __construct(private readonly DecisionResult|\Throwable $response) {}

            public function decide(array|string $state, array $questions): DecisionResult
            {
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

        $this->app->bind(DecisionDriverResolver::class, fn ($app) => new class($app->make(DecisionDriverFactory::class), $fake) extends DecisionDriverResolver
        {
            public function __construct(DecisionDriverFactory $factory, private readonly DecisionModel $fake)
            {
                parent::__construct($factory);
            }

            public function resolve(?Team $team, ?string $driverName = null): ResolvedDecisionDriver
            {
                $real = parent::resolve($team, $driverName);

                return new ResolvedDecisionDriver($this->fake, $real->name, $real->source, $real->creditsPerCall);
            }
        });
    }

    private function skill(array $configuration): Skill
    {
        return Skill::factory()->create([
            'team_id' => $this->team->id,
            'type' => SkillType::Decision->value,
            'configuration' => $configuration,
        ]);
    }

    public function test_a_decision_skill_returns_typed_answers_and_records_the_cost(): void
    {
        $this->fakeDriver(new DecisionResult(
            answers: ['tier' => new ChoiceAnswer('high', ['high' => 0.88, 'low' => 0.12], 0.88)],
            model: 'jev-1.13.0', inputTokens: 40, latencyMs: 120,
        ));

        $result = app(ExecuteDecisionSkillAction::class)->execute(
            $this->skill(['questions' => ['tier' => ['type' => 'choice']]]),
            ['state' => 'a big customer complained'],
            $this->team->id,
            (string) Str::uuid(),
        );

        $this->assertSame('high', $result['output']['answers']['tier']['value']);
        $this->assertSame('jev-1.13.0', $result['output']['model']);
        $this->assertSame('platform', $result['output']['decided_by']);
        $this->assertSame('completed', $result['execution']->status);
        $this->assertSame(2, $result['execution']->cost_credits);
    }

    /**
     * ExecuteSkillAction reserves and settles around its own LLM call, but a
     * specialized type returns before that — so a decision skill on the platform
     * key has to write its own ledger entry or the credits are never charged.
     */
    public function test_a_platform_key_call_debits_the_credit_ledger(): void
    {
        $userId = User::factory()->create(['current_team_id' => $this->team->id])->id;

        CreditLedger::create([
            'team_id' => $this->team->id,
            'user_id' => $userId,
            'type' => LedgerType::Purchase->value,
            'amount' => 100,
            'balance_after' => 100,
            'description' => 'seed',
        ]);

        $this->fakeDriver(new DecisionResult(
            answers: ['tier' => new ChoiceAnswer('high', ['high' => 0.9, 'low' => 0.1], 0.9)],
            model: 'jev-1.13.0', inputTokens: 10, latencyMs: 30,
        ));

        $result = app(ExecuteDecisionSkillAction::class)->execute(
            $this->skill(['questions' => ['tier' => ['type' => 'choice']]]),
            ['state' => 'x'],
            $this->team->id,
            $userId,
        );

        $this->assertSame('completed', $result['execution']->status);

        $spend = CreditLedger::withoutGlobalScopes()
            ->where('team_id', $this->team->id)
            ->whereNot('type', LedgerType::Purchase->value)
            ->sum('amount');

        $this->assertSame(-2, (int) $spend);
    }

    public function test_a_team_key_call_is_not_charged(): void
    {
        $userId = User::factory()->create(['current_team_id' => $this->team->id])->id;

        CreditLedger::create([
            'team_id' => $this->team->id,
            'user_id' => $userId,
            'type' => LedgerType::Purchase->value,
            'amount' => 100,
            'balance_after' => 100,
            'description' => 'seed',
        ]);

        TeamProviderCredential::create([
            'team_id' => $this->team->id,
            'provider' => 'typesafe',
            'name' => 'Typesafe',
            'credentials' => ['api_key' => 'team-key'],
            'is_active' => true,
        ]);

        $this->fakeDriver(new DecisionResult(
            answers: ['q' => new ChoiceAnswer('yes', null, 0.9)],
            model: 'jev-1.13.0', inputTokens: 10, latencyMs: 30,
        ));

        $result = app(ExecuteDecisionSkillAction::class)->execute(
            $this->skill(['questions' => ['q' => ['type' => 'choice']]]),
            ['state' => 'x'],
            $this->team->id,
            $userId,
        );

        $this->assertSame('team', $result['output']['decided_by']);
        $this->assertSame(0, $result['execution']->cost_credits);

        $spend = CreditLedger::withoutGlobalScopes()
            ->where('team_id', $this->team->id)
            ->whereNot('type', LedgerType::Purchase->value)
            ->sum('amount');

        $this->assertSame(0, (int) $spend);
    }

    public function test_a_driver_failure_releases_the_whole_reservation(): void
    {
        $userId = User::factory()->create(['current_team_id' => $this->team->id])->id;

        CreditLedger::create([
            'team_id' => $this->team->id,
            'user_id' => $userId,
            'type' => LedgerType::Purchase->value,
            'amount' => 100,
            'balance_after' => 100,
            'description' => 'seed',
        ]);

        $this->fakeDriver(new \RuntimeException('decision endpoint down'));

        $result = app(ExecuteDecisionSkillAction::class)->execute(
            $this->skill(['questions' => ['q' => ['type' => 'choice']]]),
            ['state' => 'x'],
            $this->team->id,
            $userId,
        );

        $this->assertSame('failed', $result['execution']->status);

        $spend = CreditLedger::withoutGlobalScopes()
            ->where('team_id', $this->team->id)
            ->whereNot('type', LedgerType::Purchase->value)
            ->sum('amount');

        $this->assertSame(0, (int) $spend);
    }

    public function test_missing_questions_fails_the_execution_with_a_recorded_message(): void
    {
        $result = app(ExecuteDecisionSkillAction::class)->execute(
            $this->skill(['driver' => 'jev']),
            ['state' => 'x'],
            $this->team->id,
            (string) Str::uuid(),
        );

        $this->assertNull($result['output']);
        $this->assertSame('failed', $result['execution']->status);
        $this->assertStringContainsString('questions', $result['execution']->error_message);
    }

    /**
     * ExecuteSkillAction used to match $skill->type (a SkillType enum) against
     * the backing strings, so no specialized type was ever short-circuited and
     * every one of them fell through to executeByType's LogicException.
     */
    public function test_execute_skill_action_routes_a_decision_skill_to_the_decision_delegate(): void
    {
        $this->fakeDriver(new DecisionResult(
            answers: ['tier' => new ChoiceAnswer('high', ['high' => 0.9, 'low' => 0.1], 0.9)],
            model: 'jev-1.13.0', inputTokens: 10, latencyMs: 30,
        ));

        $result = app(ExecuteSkillAction::class)->execute(
            $this->skill(['questions' => ['tier' => ['type' => 'choice']]]),
            ['state' => 'a big customer complained'],
            $this->team->id,
            (string) Str::uuid(),
        );

        $this->assertSame('completed', $result['execution']->status);
        $this->assertSame('high', $result['output']['answers']['tier']['value']);
    }

    public function test_a_driver_failure_is_recorded_on_the_execution(): void
    {
        $this->fakeDriver(new \RuntimeException('decision endpoint down'));

        $result = app(ExecuteDecisionSkillAction::class)->execute(
            $this->skill(['questions' => ['q' => ['type' => 'choice']]]),
            ['state' => 'x'],
            $this->team->id,
            (string) Str::uuid(),
        );

        $this->assertNull($result['output']);
        $this->assertSame('failed', $result['execution']->status);
        $this->assertStringContainsString('down', $result['execution']->error_message);
    }
}
