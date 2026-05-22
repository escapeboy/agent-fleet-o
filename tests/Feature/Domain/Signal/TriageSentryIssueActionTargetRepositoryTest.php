<?php

namespace Tests\Feature\Domain\Signal;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Actions\TriageSentryIssueAction;
use App\Domain\Signal\Enums\SentryTriageOutcome;
use App\Domain\Signal\Enums\SignalStatus;
use App\Domain\Signal\Models\Signal;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Services\ProviderResolver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Submodule-aware target_repository routing for Sentry Watchdog phase 1.
 *
 * Verifies that the triage action routes delegation to either the cloud
 * parent (escapeboy/agent-fleet) or the open-core submodule
 * (escapeboy/agent-fleet-o) based on suspect_files, and that mixed lists
 * fall back to investigate-only with a "mixed" reason.
 */
class TriageSentryIssueActionTargetRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::factory()->create(['owner_id' => $this->user->id]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);

        Queue::fake();
        Cache::flush();

        config([
            'sentry_watchdog.mode' => 'phase1',
            'sentry_watchdog.confidence_threshold' => 0.7,
        ]);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    private function fakeGateway(string $content): void
    {
        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')->andReturn(new AiResponseDTO(
            content: $content,
            parsedOutput: null,
            usage: new AiUsageDTO(promptTokens: 200, completionTokens: 80, costCredits: 1),
            provider: 'anthropic',
            model: 'claude-sonnet-4-5',
            latencyMs: 120,
        ));
        $this->app->instance(AiGatewayInterface::class, $gateway);

        $resolver = Mockery::mock(ProviderResolver::class);
        $resolver->shouldReceive('resolve')->andReturn([
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-5',
        ]);
        $this->app->instance(ProviderResolver::class, $resolver);
    }

    /**
     * @param  array<string, mixed>  $investigation
     */
    private function triageJson(array $investigation): string
    {
        return json_encode(array_merge([
            'root_cause' => 'Null dereference in renderer.',
            'confidence' => 0.9,
            'suspect_files' => ['app/Http/Controllers/OrderController.php'],
            'estimated_diff_lines' => 12,
            'is_critical' => false,
            'summary' => 'Renderer crashes on missing field.',
        ], $investigation), JSON_THROW_ON_ERROR);
    }

    private function makeSentrySignal(): Signal
    {
        return Signal::create([
            'team_id' => $this->team->id,
            'source_type' => 'integration',
            'source_identifier' => 'sentry',
            'project_key' => 'fleetq',
            'payload' => [
                'source_type' => 'sentry',
                'source_id' => 'sentry:'.bin2hex(random_bytes(4)),
                'payload' => [
                    'id' => 'sentry-issue-99',
                    'title' => 'TypeError: Cannot read property of null',
                    'culprit' => 'App\\Http\\Controllers\\OrderController::show',
                    'level' => 'error',
                    'count' => 5,
                    'permalink' => 'https://sentry.example.com/issues/99/',
                    'metadata' => ['type' => 'TypeError', 'value' => 'Cannot read property of null'],
                ],
            ],
            'content_hash' => hash('sha256', uniqid('sentry-target-', true)),
            'received_at' => now(),
            'status' => SignalStatus::Received,
        ]);
    }

    public function test_pure_parent_suspect_files_route_to_parent_repository(): void
    {
        $this->fakeGateway($this->triageJson([
            'suspect_files' => [
                'app/Http/Controllers/OrderController.php',
                'app/Services/PricingService.php',
            ],
            'confidence' => 0.9,
            'estimated_diff_lines' => 14,
        ]));

        $signal = $this->makeSentrySignal();

        $result = app(TriageSentryIssueAction::class)->execute($signal);

        $this->assertSame(SentryTriageOutcome::Delegated, $result->outcome);
        $this->assertNotNull($result->experimentId);
        $this->assertSame(1, Experiment::query()->count());

        $signal->refresh();
        $this->assertSame('escapeboy/agent-fleet', $signal->payload['target_repository']);
    }

    public function test_pure_base_suspect_files_route_to_base_repository(): void
    {
        // Paths chosen to clear Rule 3's sensitive-fragment list (domain/signal,
        // domain/agent, etc. all force T4 regardless of repo).
        $this->fakeGateway($this->triageJson([
            'suspect_files' => [
                'base/app/Domain/Tool/Models/Tool.php',
                'base/app/Livewire/Tools/ToolDetailPage.php',
            ],
            'confidence' => 0.92,
            'estimated_diff_lines' => 18,
        ]));

        $signal = $this->makeSentrySignal();

        $result = app(TriageSentryIssueAction::class)->execute($signal);

        $this->assertSame(SentryTriageOutcome::Delegated, $result->outcome);
        $this->assertNotNull($result->experimentId);
        $this->assertSame(1, Experiment::query()->count());

        $signal->refresh();
        $this->assertSame('escapeboy/agent-fleet-o', $signal->payload['target_repository']);
    }

    public function test_mixed_suspect_files_fall_back_to_investigate_only(): void
    {
        $this->fakeGateway($this->triageJson([
            'suspect_files' => [
                'app/Http/Controllers/OrderController.php',
                'base/app/Domain/Signal/Models/Signal.php',
            ],
            'confidence' => 0.95,
            'estimated_diff_lines' => 22,
        ]));

        $signal = $this->makeSentrySignal();

        $result = app(TriageSentryIssueAction::class)->execute($signal);

        $this->assertSame(SentryTriageOutcome::InvestigateOnly, $result->outcome);
        $this->assertNull($result->experimentId);
        $this->assertSame(0, Experiment::query()->count());
        $this->assertNotEmpty($result->suspectFiles);
        $this->assertStringContainsString('mixed', $result->suspectFiles[0]);
    }

    public function test_integration_override_routes_all_files_to_configured_repository(): void
    {
        // A project whose code lives in a single repo outside the agent-fleet
        // monorepo (e.g. signalio-backend) declares config['target_repository'];
        // every suspect file then routes there regardless of the base/ prefix.
        $this->fakeGateway($this->triageJson([
            'suspect_files' => [
                'app/Jobs/CrawlInstitutionJob.php',
                'app/Models/Alert.php',
            ],
            'confidence' => 0.9,
            'estimated_diff_lines' => 10,
        ]));

        $signal = $this->makeSentrySignal();

        $result = app(TriageSentryIssueAction::class)
            ->execute($signal, 'escapeboy/signalio-backend');

        $this->assertSame(SentryTriageOutcome::Delegated, $result->outcome);
        $this->assertNotNull($result->experimentId);

        $signal->refresh();
        $this->assertSame('escapeboy/signalio-backend', $signal->payload['target_repository']);
    }

    public function test_sentry_project_object_does_not_break_delegation(): void
    {
        // Regression: prod Sentry signals carry payload['project'] as an object
        // and no project_key column. DelegateBugReportToAgentAction::sanitize()
        // used to receive that array and throw a TypeError, silently blocking
        // every PR (the watchdog reported 0 delegations across every run).
        $this->fakeGateway($this->triageJson([
            'suspect_files' => ['app/Jobs/CrawlInstitutionJob.php'],
            'confidence' => 0.9,
            'estimated_diff_lines' => 8,
        ]));

        $signal = Signal::create([
            'team_id' => $this->team->id,
            'source_type' => 'integration',
            'source_identifier' => 'sentry',
            'project_key' => null,
            'payload' => [
                'source_type' => 'sentry',
                'source_id' => 'sentry:'.bin2hex(random_bytes(4)),
                'payload' => [
                    'id' => 'sentry-issue-762',
                    'title' => 'N+1 Query',
                    'culprit' => 'App\\Jobs\\CrawlInstitutionJob',
                    'level' => 'error',
                    'count' => 12,
                    'project' => ['id' => 6, 'slug' => 'signalio-backend', 'name' => 'Signalio Backend'],
                    'permalink' => 'https://sentry.example.com/issues/762/',
                    'metadata' => ['type' => 'N+1 Query', 'value' => 'N+1 query in CrawlInstitutionJob'],
                ],
            ],
            'content_hash' => hash('sha256', uniqid('sentry-proj-obj-', true)),
            'received_at' => now(),
            'status' => SignalStatus::Received,
        ]);

        $result = app(TriageSentryIssueAction::class)
            ->execute($signal, 'escapeboy/signalio-backend');

        $this->assertSame(SentryTriageOutcome::Delegated, $result->outcome);
        $this->assertNotNull($result->experimentId);
        $this->assertSame(1, Experiment::query()->count());
    }

    public function test_stacktrace_frames_are_included_in_triage_prompt(): void
    {
        $captured = null;
        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')
            ->andReturnUsing(function ($request) use (&$captured) {
                $captured = $request->userPrompt;

                return new AiResponseDTO(
                    content: $this->triageJson([]),
                    parsedOutput: null,
                    usage: new AiUsageDTO(promptTokens: 200, completionTokens: 80, costCredits: 1),
                    provider: 'anthropic',
                    model: 'claude-sonnet-4-5',
                    latencyMs: 120,
                );
            });
        $this->app->instance(AiGatewayInterface::class, $gateway);

        $resolver = Mockery::mock(ProviderResolver::class);
        $resolver->shouldReceive('resolve')->andReturn([
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-5',
        ]);
        $this->app->instance(ProviderResolver::class, $resolver);

        $signal = $this->makeSentrySignal();
        $payload = $signal->payload;
        $payload['payload']['suspect_frames'] = [
            'app/Jobs/CrawlInstitutionJob.php:42 in handle()',
            'app/Models/Alert.php:88 in scopeActive()',
        ];
        $signal->payload = $payload;
        $signal->save();

        app(TriageSentryIssueAction::class)->execute($signal);

        $this->assertNotNull($captured);
        $this->assertStringContainsString('In-app stacktrace frames', $captured);
        $this->assertStringContainsString('app/Jobs/CrawlInstitutionJob.php:42 in handle()', $captured);
    }
}
