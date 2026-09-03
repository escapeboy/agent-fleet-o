<?php

namespace Tests\Feature\Domain\Memory;

use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Memory\Actions\DistillTeamEventsAction;
use App\Domain\Memory\Actions\StoreMemoryAction;
use App\Domain\Shared\Exceptions\AiAccessUnavailableException;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Exceptions\VpsLocalAgentException;
use App\Infrastructure\AI\Services\ProviderResolver;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DistillTeamEventsActionTest extends TestCase
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
    }

    private function auditEntry(string $event, CarbonInterface $at): void
    {
        AuditEntry::create([
            'team_id' => $this->team->id,
            'event' => $event,
            'subject_type' => null,
            'properties' => [],
            'created_at' => $at,
        ]);
    }

    private function makeAction(AiGatewayInterface $gateway, StoreMemoryAction $store): DistillTeamEventsAction
    {
        return new DistillTeamEventsAction($gateway, $store, app(ProviderResolver::class));
    }

    private function fakeGateway(string $content): AiGatewayInterface
    {
        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')->once()->andReturn(new AiResponseDTO(
            content: $content,
            parsedOutput: null,
            usage: new AiUsageDTO(10, 20, 1),
            provider: 'anthropic',
            model: 'claude-haiku-4-5',
            latencyMs: 100,
        ));

        return $gateway;
    }

    public function test_distils_events_into_a_memory_and_advances_watermark(): void
    {
        $this->auditEntry('experiment.transitioned', now()->subHours(2));
        $this->auditEntry('approval.approved', now()->subHours(1));
        $this->auditEntry('budget.exceeded', now()->subMinutes(20));

        $store = Mockery::mock(StoreMemoryAction::class);
        $store->shouldReceive('execute')->once()->andReturn(['memory-1']);

        $action = $this->makeAction($this->fakeGateway('- Budget exceeded twice this window.'), $store);
        $result = $action->execute($this->team->id);

        $this->assertSame(3, $result['events']);
        $this->assertSame(1, $result['stored']);
        $this->assertFalse($result['dry_run']);
        $this->assertNotNull($this->team->fresh()->settings['memory']['last_event_distill_at'] ?? null);
    }

    public function test_dry_run_gathers_without_llm_or_store(): void
    {
        $this->auditEntry('experiment.transitioned', now()->subHours(2));
        $this->auditEntry('approval.approved', now()->subHours(1));

        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldNotReceive('complete');
        $store = Mockery::mock(StoreMemoryAction::class);
        $store->shouldNotReceive('execute');

        $result = $this->makeAction($gateway, $store)->execute($this->team->id, null, true);

        $this->assertSame(2, $result['events']);
        $this->assertSame(0, $result['stored']);
        $this->assertTrue($result['dry_run']);
        $this->assertArrayNotHasKey('memory', $this->team->fresh()->settings ?? []);
    }

    public function test_empty_window_is_a_noop(): void
    {
        // Only stale events, well outside the default 24h window.
        $this->auditEntry('experiment.transitioned', now()->subDays(5));

        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldNotReceive('complete');
        $store = Mockery::mock(StoreMemoryAction::class);
        $store->shouldNotReceive('execute');

        $result = $this->makeAction($gateway, $store)->execute($this->team->id);

        $this->assertSame(0, $result['events']);
        $this->assertSame(0, $result['stored']);
        $this->assertArrayNotHasKey('memory', $this->team->fresh()->settings ?? []);
    }

    public function test_since_override_limits_the_window(): void
    {
        $this->auditEntry('old.event', now()->subDays(2));
        $this->auditEntry('recent.event', now()->subHour());

        $store = Mockery::mock(StoreMemoryAction::class);
        $store->shouldReceive('execute')->once()->andReturn(['memory-1']);

        $action = $this->makeAction($this->fakeGateway('- One recent event.'), $store);
        $result = $action->execute($this->team->id, now()->subHours(3));

        $this->assertSame(1, $result['events']);
    }

    public function test_skips_silently_when_gateway_reports_no_available_providers(): void
    {
        // Sentry issue FLEETQ-81: without this guard, the hourly cron lets the
        // gateway's "No available providers in fallback chain" escape Sentry as
        // an error. The action should treat it as a no-op and advance the
        // watermark so the same window isn't rescanned indefinitely.
        $this->auditEntry('experiment.transitioned', now()->subHour());

        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')
            ->once()
            ->andThrow(new \RuntimeException('No available providers in fallback chain'));

        $store = Mockery::mock(StoreMemoryAction::class);
        $store->shouldNotReceive('execute');

        $result = $this->makeAction($gateway, $store)->execute($this->team->id);

        $this->assertSame(1, $result['events']);
        $this->assertSame(0, $result['stored']);
        $this->assertNotNull($this->team->fresh()->settings['memory']['last_event_distill_at'] ?? null);
    }

    public function test_skips_when_teams_only_credential_is_for_a_different_provider(): void
    {
        // DistillTeamEventsJob's pre-flight gate (TeamAiAccessChecker::canUseAi)
        // is provider-agnostic, so a BYOK team holding a key for some *other*
        // provider clears it and reaches this action. PrismAiGateway then throws
        // AiAccessUnavailableException (resolveCredential: no credential for the
        // distillation provider, and the plan carries no platform_llm_fallback).
        // That is expected backpressure — the team must be skipped and its
        // watermark advanced, not pushed into failed_jobs on every hourly run.
        // (#824/#847/#1035/#1064)
        config(['memory.distillation.model' => 'groq/llama-3.3-70b-versatile']);

        $this->auditEntry('experiment.transitioned', now()->subHour());

        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')
            ->once()
            ->with(Mockery::on(fn (AiRequestDTO $request) => $request->provider === 'groq'))
            ->andThrow(AiAccessUnavailableException::forTeam());

        $store = Mockery::mock(StoreMemoryAction::class);
        $store->shouldNotReceive('execute');

        $result = $this->makeAction($gateway, $store)->execute($this->team->id);

        $this->assertSame(1, $result['events']);
        $this->assertSame(0, $result['stored']);
        $this->assertNotNull($this->team->fresh()->settings['memory']['last_event_distill_at'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function setTeamDefault(string $provider, string $model, array $extra = []): void
    {
        $this->team->update([
            'settings' => array_merge([
                'default_llm_provider' => $provider,
                'default_llm_model' => $model,
            ], $extra),
        ]);
    }

    private function gatewayExpecting(callable $assert, string $content = '- Едно нещо.'): AiGatewayInterface
    {
        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')
            ->once()
            ->with(Mockery::on(fn (AiRequestDTO $request) => $assert($request) === true))
            ->andReturn(new AiResponseDTO(
                content: $content,
                parsedOutput: null,
                usage: new AiUsageDTO(10, 20, 1),
                provider: 'x',
                model: 'y',
                latencyMs: 100,
            ));

        return $gateway;
    }

    public function test_team_default_provider_wins_over_the_distillation_model(): void
    {
        // Екипът е изразил предпочитание — то е водещо, дестилационният модел
        // не бива да го пренаписва.
        config(['memory.distillation.model' => 'anthropic/claude-haiku-4-5']);
        $this->setTeamDefault('openai', 'gpt-4o');
        $this->auditEntry('experiment.transitioned', now()->subHour());

        $store = Mockery::mock(StoreMemoryAction::class);
        $store->shouldReceive('execute')->once()->andReturn(['memory-1']);

        $gateway = $this->gatewayExpecting(
            fn (AiRequestDTO $r) => $r->provider === 'openai' && $r->model === 'gpt-4o',
        );

        $result = $this->makeAction($gateway, $store)->execute($this->team->id);

        $this->assertSame(1, $result['stored']);
    }

    public function test_team_without_preference_falls_back_to_the_cheap_distillation_model(): void
    {
        // Без предпочитание на екипа (settings празни, GlobalSetting NULL) —
        // тогава и само тогава важи евтиният дестилационен модел.
        config(['memory.distillation.model' => 'anthropic/claude-haiku-4-5']);
        $this->auditEntry('experiment.transitioned', now()->subHour());

        $store = Mockery::mock(StoreMemoryAction::class);
        $store->shouldReceive('execute')->once()->andReturn(['memory-1']);

        $gateway = $this->gatewayExpecting(
            fn (AiRequestDTO $r) => $r->provider === 'anthropic' && $r->model === 'claude-haiku-4-5',
        );

        $result = $this->makeAction($gateway, $store)->execute($this->team->id);

        $this->assertSame(1, $result['stored']);
    }

    public function test_groq_is_not_called_for_a_team_with_its_own_default(): void
    {
        // Дефект-гард за FLEETQ-BJ: production държи
        // MEMORY_DISTILLATION_MODEL=groq/openai/gpt-oss-120b, а префиксът носи
        // доставчика. Преди поправката всеки екип — включително тези на
        // claude-code-vps — беше пращан към Groq и получаваше 401 всяка нощ.
        config(['memory.distillation.model' => 'groq/openai/gpt-oss-120b']);
        $this->setTeamDefault('claude-code-vps', 'claude-sonnet-4-5');
        $this->auditEntry('experiment.transitioned', now()->subHour());

        $store = Mockery::mock(StoreMemoryAction::class);
        $store->shouldReceive('execute')->once()->andReturn(['memory-1']);

        $gateway = $this->gatewayExpecting(
            fn (AiRequestDTO $r) => $r->provider !== 'groq' && $r->provider === 'claude-code-vps',
        );

        $this->makeAction($gateway, $store)->execute($this->team->id);
    }

    public function test_skips_when_the_vps_local_agent_is_not_allowed_for_the_team(): void
    {
        // Екип, чийто default сочи claude-code-vps, но не е whitelist-нат
        // (на production: „Test кантора 1"), получава VpsLocalAgentException.
        // Във фоновата нощна задача това е backpressure — пропуска се като
        // останалите случаи, watermark-ът се придвижва и нищо не стига до
        // Sentry. Съзнателно НЕ се филтрира в BeforeSendFilter: интерактивно
        // потребителят трябва да види съобщението.
        $this->setTeamDefault('claude-code-vps', 'claude-sonnet-4-5');
        $this->auditEntry('experiment.transitioned', now()->subHour());

        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')
            ->once()
            ->andThrow(VpsLocalAgentException::notAllowed());

        $store = Mockery::mock(StoreMemoryAction::class);
        $store->shouldNotReceive('execute');

        $result = $this->makeAction($gateway, $store)->execute($this->team->id);

        $this->assertSame(1, $result['events']);
        $this->assertSame(0, $result['stored']);
        $this->assertNotNull($this->team->fresh()->settings['memory']['last_event_distill_at'] ?? null);
    }
}
