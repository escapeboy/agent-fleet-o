<?php

namespace Tests\Feature\Mcp\Decision;

use App\Domain\Budget\Enums\LedgerType;
use App\Domain\Budget\Models\CreditLedger;
use App\Domain\Decision\Contracts\DecisionModel;
use App\Domain\Decision\DTOs\ChoiceAnswer;
use App\Domain\Decision\DTOs\DecisionResult;
use App\Domain\Decision\DTOs\NoulAnswer;
use App\Domain\Decision\DTOs\ResolvedDecisionDriver;
use App\Domain\Decision\Services\DecisionDriverFactory;
use App\Domain\Decision\Services\DecisionDriverResolver;
use App\Domain\Shared\Models\Team;
use App\Mcp\Servers\AgentFleetServer;
use App\Mcp\Tools\Decision\DecisionAskTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Tests\TestCase;

class DecisionAskToolTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'T '.bin2hex(random_bytes(3)),
            'slug' => 't-'.bin2hex(random_bytes(3)),
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($user, ['role' => 'owner']);
        $this->actingAs($user);
        app()->instance('mcp.team_id', $this->team->id);

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

    private function seedCredits(int $amount = 100): void
    {
        CreditLedger::create([
            'team_id' => $this->team->id,
            'user_id' => auth()->id(),
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

    /**
     * The node and the skill both bill this case. The tool did not, which made
     * it the one user-reachable surface that spent the platform key for free.
     */
    public function test_a_platform_key_call_debits_credits_per_call(): void
    {
        $this->seedCredits();
        $this->fakeDriver(new DecisionResult(
            answers: ['q' => new ChoiceAnswer('yes', null, 0.9)],
            model: 'jev-1.13.0', inputTokens: 10, latencyMs: 20,
        ));

        $response = app(DecisionAskTool::class)->handle(new Request([
            'state' => 'x', 'questions' => ['q' => ['type' => 'choice']],
        ]));

        $this->assertSame('platform', json_decode($this->text($response), true)['decided_by']);
        $this->assertSame(-2, $this->spend());
    }

    public function test_a_driver_failure_releases_the_whole_reservation(): void
    {
        $this->seedCredits();
        $this->fakeDriver(new \RuntimeException('decision endpoint down'));

        app(DecisionAskTool::class)->handle(new Request([
            'state' => 'x', 'questions' => ['q' => ['type' => 'choice']],
        ]));

        $this->assertSame(0, $this->spend());
    }

    public function test_returns_typed_answers_with_model_and_source(): void
    {
        $this->fakeDriver(new DecisionResult(
            answers: [
                'urgent' => new ChoiceAnswer('yes', ['yes' => 0.9, 'no' => 0.1], 0.9),
                'risk' => new NoulAnswer(0.4),
            ],
            model: 'jev-1.13.0', inputTokens: 64, latencyMs: 210,
        ));

        $response = app(DecisionAskTool::class)->handle(new Request([
            'state' => 'the server is on fire',
            'questions' => ['urgent' => ['type' => 'choice'], 'risk' => ['type' => 'noul']],
        ]));

        $payload = json_decode($this->text($response), true);

        $this->assertSame('yes', $payload['answers']['urgent']['value']);
        $this->assertSame(0.4, $payload['answers']['risk']['value']);
        $this->assertSame('jev-1.13.0', $payload['model']);
        $this->assertSame('platform', $payload['decided_by']);
        $this->assertSame([], $payload['low_confidence']);
    }

    public function test_min_confidence_flags_null_confidence_answers(): void
    {
        $this->fakeDriver(new DecisionResult(
            answers: ['risk' => new NoulAnswer(0.4), 'sure' => new ChoiceAnswer('yes', null, 0.99)],
            model: 'jev-1.13.0', inputTokens: 10, latencyMs: 20,
        ));

        $response = app(DecisionAskTool::class)->handle(new Request([
            'state' => 'x',
            'questions' => ['risk' => ['type' => 'noul'], 'sure' => ['type' => 'choice']],
            'min_confidence' => 0.7,
        ]));

        $payload = json_decode($this->text($response), true);
        $this->assertSame(['risk'], $payload['low_confidence']);
    }

    public function test_no_team_bound_is_permission_denied(): void
    {
        app()->forgetInstance('mcp.team_id');
        auth()->user()->update(['current_team_id' => null]);
        $this->actingAs(auth()->user()->fresh());

        $response = app(DecisionAskTool::class)->handle(new Request([
            'state' => 'x', 'questions' => ['q' => ['type' => 'choice']],
        ]));

        $payload = json_decode($this->text($response), true);
        $this->assertSame('PERMISSION_DENIED', $payload['error']['code']);
    }

    public function test_unknown_driver_returns_a_structured_error(): void
    {
        $response = app(DecisionAskTool::class)->handle(new Request([
            'state' => 'x',
            'questions' => ['q' => ['type' => 'choice']],
            'driver' => 'does-not-exist',
        ]));

        $payload = json_decode($this->text($response), true);
        $this->assertArrayHasKey('error', $payload);
        $this->assertStringContainsString('does-not-exist', $payload['error']['message']);
    }

    public function test_driver_failure_returns_a_structured_error(): void
    {
        $this->fakeDriver(new \RuntimeException('typesafe unreachable'));

        $response = app(DecisionAskTool::class)->handle(new Request([
            'state' => 'x', 'questions' => ['q' => ['type' => 'choice']],
        ]));

        $payload = json_decode($this->text($response), true);
        $this->assertArrayHasKey('error', $payload);
        $this->assertStringContainsString('unreachable', $payload['error']['message']);
    }

    public function test_the_tool_is_registered_in_both_servers(): void
    {
        // The previous sprint shipped a tool that reached only the base server,
        // leaving it unreachable in the cloud deployment. Guard against a repeat.
        $base = (new \ReflectionClass(AgentFleetServer::class))->getDefaultProperties()['tools'];
        $this->assertContains(DecisionAskTool::class, $base, 'missing from AgentFleetServer');

        $cloudServer = 'Cloud\\Mcp\\Servers\\CloudAgentFleetServer';
        if (class_exists($cloudServer)) {
            $cloud = (new \ReflectionClass($cloudServer))->getDefaultProperties()['tools'];
            $this->assertContains(DecisionAskTool::class, $cloud, 'missing from CloudAgentFleetServer');
        }
    }

    private function text(Response $response): string
    {
        return (string) $response->content();
    }
}
