<?php

namespace Tests\Feature\Domain\Memory;

use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Memory\Actions\DistillTeamEventsAction;
use App\Domain\Memory\Actions\StoreMemoryAction;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
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

        $action = new DistillTeamEventsAction($this->fakeGateway('- Budget exceeded twice this window.'), $store);
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

        $result = (new DistillTeamEventsAction($gateway, $store))->execute($this->team->id, null, true);

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

        $result = (new DistillTeamEventsAction($gateway, $store))->execute($this->team->id);

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

        $action = new DistillTeamEventsAction($this->fakeGateway('- One recent event.'), $store);
        $result = $action->execute($this->team->id, now()->subHours(3));

        $this->assertSame(1, $result['events']);
    }
}
