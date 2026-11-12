<?php

namespace Tests\Feature\Livewire\Agents;

use App\Domain\Agent\Models\Agent;
use App\Domain\Bridge\Enums\BridgeConnectionStatus;
use App\Domain\Bridge\Models\BridgeConnection;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Exceptions\BridgeNotConnectedException;
use App\Livewire\Agents\AgentDetailPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AgentDetailBridgeOfflineBannerTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_bridge_agent_without_connection_shows_offline_banner(): void
    {
        Livewire::test(AgentDetailPage::class, ['agent' => $this->agent('bridge_llm', 'llama3.2')])
            ->assertSee('FleetQ Bridge offline')
            ->assertSee(BridgeNotConnectedException::DOWNLOAD_URL)
            ->assertSee(route('team.settings'));
    }

    public function test_bridge_agent_with_active_connection_hides_banner(): void
    {
        BridgeConnection::create([
            'team_id' => $this->team->id,
            'session_id' => 'relay-banner',
            'priority' => 1,
            'status' => BridgeConnectionStatus::Connected,
            'endpoints' => ['agents' => [], 'llm_endpoints' => [
                ['name' => 'ollama', 'base_url' => 'http://127.0.0.1:11434', 'online' => true, 'models' => ['llama3.2']],
            ]],
            'connected_at' => now(),
            'last_seen_at' => now(),
        ]);

        Livewire::test(AgentDetailPage::class, ['agent' => $this->agent('bridge_llm', 'llama3.2')])
            ->assertDontSee('FleetQ Bridge offline');
    }

    public function test_non_bridge_agent_hides_banner(): void
    {
        Livewire::test(AgentDetailPage::class, ['agent' => $this->agent('anthropic', 'claude-sonnet-4-5')])
            ->assertDontSee('FleetQ Bridge offline');
    }
}
