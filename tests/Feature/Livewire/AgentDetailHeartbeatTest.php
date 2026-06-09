<?php

namespace Tests\Feature\Livewire;

use App\Domain\Agent\Models\Agent;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Livewire\Agents\AgentDetailPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class AgentDetailHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $team = Team::create([
            'name' => 'T',
            'slug' => 't-'.uniqid(),
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $team->id]);
        $this->actingAs($user);
        $this->agent = Agent::factory()->create(['team_id' => $team->id]);
    }

    public function test_save_heartbeat_persists_the_definition(): void
    {
        Livewire::test(AgentDetailPage::class, ['agent' => $this->agent])
            ->call('startEditHeartbeat')
            ->set('heartbeatCron', '0 * * * *')
            ->set('heartbeatPrompt', 'Run your scheduled check-in.')
            ->set('heartbeatEnabled', true)
            ->call('saveHeartbeat')
            ->assertHasNoErrors()
            ->assertSet('editingHeartbeat', false);

        $definition = $this->agent->fresh()->heartbeat_definition;
        $this->assertSame('0 * * * *', $definition['cron']);
        $this->assertSame('Run your scheduled check-in.', $definition['prompt']);
        $this->assertTrue($definition['enabled']);
        $this->assertNull($definition['next_run_at']);
    }

    public function test_save_heartbeat_rejects_too_frequent_schedule(): void
    {
        Livewire::test(AgentDetailPage::class, ['agent' => $this->agent])
            ->call('startEditHeartbeat')
            ->set('heartbeatCron', '* * * * *') // every minute
            ->set('heartbeatPrompt', 'too often')
            ->call('saveHeartbeat')
            ->assertHasErrors(['heartbeatCron']);

        $this->assertNull($this->agent->fresh()->heartbeat_definition);
    }

    public function test_save_heartbeat_rejects_invalid_cron(): void
    {
        Livewire::test(AgentDetailPage::class, ['agent' => $this->agent])
            ->call('startEditHeartbeat')
            ->set('heartbeatCron', 'not a cron')
            ->set('heartbeatPrompt', 'x')
            ->call('saveHeartbeat')
            ->assertHasErrors(['heartbeatCron']);

        $this->assertNull($this->agent->fresh()->heartbeat_definition);
    }

    public function test_unauthorized_user_cannot_save_heartbeat(): void
    {
        // Base 'edit-content' gate is permissive; force-deny to exercise the
        // per-action authorize() guard.
        Gate::define('edit-content', fn () => false);

        Livewire::test(AgentDetailPage::class, ['agent' => $this->agent])
            ->set('editingHeartbeat', true)
            ->set('heartbeatCron', '0 * * * *')
            ->set('heartbeatPrompt', 'should not save')
            ->call('saveHeartbeat')
            ->assertForbidden();

        $this->assertNull($this->agent->fresh()->heartbeat_definition);
    }

    public function test_dry_run_returns_a_result(): void
    {
        $this->mock(AiGatewayInterface::class, function ($mock) {
            $mock->shouldReceive('complete')->once()->andReturn(new AiResponseDTO(
                content: 'dry-run output',
                parsedOutput: null,
                usage: new AiUsageDTO(promptTokens: 10, completionTokens: 5, costCredits: 3),
                provider: 'anthropic',
                model: 'claude-sonnet-4-5',
                latencyMs: 42,
            ));
        });

        $component = Livewire::test(AgentDetailPage::class, ['agent' => $this->agent])
            ->set('dryRunInput', 'Hello agent')
            ->call('dryRun')
            ->assertHasNoErrors()
            ->assertSet('dryRunError', null);

        $result = $component->get('dryRunResult');
        $this->assertSame('dry-run output', $result['output']);
        $this->assertSame('anthropic', $result['provider']);
        $this->assertSame(3, $result['cost_credits']);
        $this->assertSame(42, $result['latency_ms']);
    }

    public function test_unauthorized_user_cannot_dry_run(): void
    {
        Gate::define('edit-content', fn () => false);

        Livewire::test(AgentDetailPage::class, ['agent' => $this->agent])
            ->set('dryRunInput', 'Hello agent')
            ->call('dryRun')
            ->assertForbidden();
    }
}
