<?php

namespace Tests\Feature\Infrastructure\AI;

use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Exceptions\BridgeNotConnectedException;
use App\Infrastructure\AI\Gateways\LocalBridgeGateway;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LocalBridgeGatewayNotConnectedTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Bridge Offline Team',
            'slug' => 'bridge-offline-team',
            'owner_id' => $user->id,
            'plan' => 'pro',
            'settings' => [],
        ]);
    }

    public static function bridgeProviders(): array
    {
        return [
            'bridge_llm' => ['bridge_llm', 'llama3.2'],
            'bridge_agent' => ['bridge_agent', 'claude-code'],
        ];
    }

    private function request(string $provider, string $model): AiRequestDTO
    {
        return new AiRequestDTO(
            provider: $provider,
            model: $model,
            systemPrompt: 'sys',
            userPrompt: 'hello',
            teamId: $this->team->id,
        );
    }

    #[Test]
    #[DataProvider('bridgeProviders')]
    public function complete_throws_bridge_not_connected_without_connection(string $provider, string $model): void
    {
        $this->expectException(BridgeNotConnectedException::class);
        $this->expectExceptionMessage('Team Settings');

        app(LocalBridgeGateway::class)->complete($this->request($provider, $model));
    }

    #[Test]
    #[DataProvider('bridgeProviders')]
    public function stream_throws_bridge_not_connected_without_connection(string $provider, string $model): void
    {
        $this->expectException(BridgeNotConnectedException::class);

        app(LocalBridgeGateway::class)->stream($this->request($provider, $model));
    }

    #[Test]
    public function exception_is_still_a_runtime_exception_for_fallback_routing(): void
    {
        $this->assertInstanceOf(\RuntimeException::class, BridgeNotConnectedException::notConnected());
        $this->assertStringContainsString(BridgeNotConnectedException::DOWNLOAD_URL, BridgeNotConnectedException::notConnected()->getMessage());
        $this->assertStringContainsString('30s', BridgeNotConnectedException::reconnectTimeout(30)->getMessage());
    }
}
