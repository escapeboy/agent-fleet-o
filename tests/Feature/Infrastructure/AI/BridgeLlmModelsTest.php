<?php

namespace Tests\Feature\Infrastructure\AI;

use App\Domain\Bridge\Enums\BridgeConnectionStatus;
use App\Domain\Bridge\Models\BridgeConnection;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Services\ProviderResolver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BridgeLlmModelsTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Bridge Models Team',
            'slug' => 'bridge-models-team',
            'owner_id' => $user->id,
            'plan' => 'pro',
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $this->team->id]);
        $this->actingAs($user);
    }

    private function makeConnection(string $session, int $priority, array $llmEndpoints, BridgeConnectionStatus $status = BridgeConnectionStatus::Connected): BridgeConnection
    {
        return BridgeConnection::create([
            'team_id' => $this->team->id,
            'session_id' => $session,
            'priority' => $priority,
            'status' => $status,
            'endpoints' => ['agents' => [], 'llm_endpoints' => $llmEndpoints],
            'connected_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    #[Test]
    public function bridge_llm_models_are_populated_from_online_endpoints(): void
    {
        $this->makeConnection('relay-a', 10, [
            ['name' => 'ollama', 'base_url' => 'http://127.0.0.1:11434', 'online' => true, 'models' => ['llama3.2', 'qwen2.5']],
            ['name' => 'lmstudio', 'base_url' => 'http://127.0.0.1:1234', 'online' => false, 'models' => ['offline-model']],
        ]);
        $this->makeConnection('relay-b', 5, [
            ['name' => 'ollama', 'base_url' => 'http://127.0.0.1:11434', 'online' => true, 'models' => ['qwen2.5', 'mistral']],
        ]);

        $providers = app(ProviderResolver::class)->availableProviders($this->team);

        $this->assertArrayHasKey('bridge_llm', $providers);
        $models = $providers['bridge_llm']['models'];
        $this->assertSame(['llama3.2', 'qwen2.5', 'mistral'], array_keys($models));
        $this->assertSame(['label' => 'llama3.2', 'input_cost' => 0, 'output_cost' => 0], $models['llama3.2']);
        $this->assertArrayNotHasKey('offline-model', $models);
    }

    #[Test]
    public function bridge_llm_is_still_removed_without_active_connection(): void
    {
        $this->makeConnection('relay-gone', 1, [
            ['name' => 'ollama', 'base_url' => 'http://127.0.0.1:11434', 'online' => true, 'models' => ['llama3.2']],
        ], BridgeConnectionStatus::Disconnected);

        $providers = app(ProviderResolver::class)->availableProviders($this->team);

        $this->assertArrayNotHasKey('bridge_llm', $providers);
    }
}
