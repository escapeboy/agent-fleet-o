<?php

namespace Tests\Feature\Livewire\Agents;

use App\Domain\Agent\Models\Agent;
use App\Domain\Bridge\Enums\BridgeConnectionStatus;
use App\Domain\Bridge\Models\BridgeConnection;
use App\Domain\Shared\Models\Team;
use App\Livewire\Agents\AgentDetailPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AgentDetailBridgeOfflineBannerTest extends TestCase
{
    use RefreshDatabase;

    private const BANNER = 'FleetQ Bridge offline';

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Banner Team',
            'slug' => 'banner-'.uniqid(),
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $this->team->id]);
        $this->actingAs($user);
    }

    private function agent(string $provider, string $model): Agent
    {
        return Agent::factory()->create([
            'team_id' => $this->team->id,
            'provider' => $provider,
            'model' => $model,
        ]);
    }

    public function test_banner_shown_for_bridge_agent_without_connection(): void
    {
        Livewire::test(AgentDetailPage::class, ['agent' => $this->agent('bridge_llm', 'llama3.2')])
            ->assertSee(self::BANNER)
            ->assertSee(route('team.settings'), false)
            ->assertSee('https://github.com/escapeboy/fleetq-bridge', false);
    }

    public function test_banner_hidden_when_bridge_is_connected(): void
    {
        BridgeConnection::create([
            'team_id' => $this->team->id,
            'session_id' => 'relay-banner',
            'priority' => 1,
            'status' => BridgeConnectionStatus::Connected,
            'endpoints' => ['agents' => [], 'llm_endpoints' => []],
            'connected_at' => now(),
            'last_seen_at' => now(),
        ]);

        Livewire::test(AgentDetailPage::class, ['agent' => $this->agent('bridge_llm', 'llama3.2')])
            ->assertDontSee(self::BANNER);
    }

    public function test_banner_hidden_for_cloud_agent(): void
    {
        Livewire::test(AgentDetailPage::class, ['agent' => $this->agent('anthropic', 'claude-sonnet-4-5')])
            ->assertDontSee(self::BANNER);
    }
}
