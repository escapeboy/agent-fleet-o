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
use RuntimeException;
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
            'settings' => [],
        ]);
    }

    public static function cases(): array
    {
        return [
            'bridge_llm complete' => ['bridge_llm', 'llama3.2', 'complete'],
            'bridge_llm stream' => ['bridge_llm', 'llama3.2', 'stream'],
            'bridge_agent complete' => ['bridge_agent', 'claude-code:default', 'complete'],
            'bridge_agent stream' => ['bridge_agent', 'claude-code:default', 'stream'],
        ];
    }

    #[Test]
    #[DataProvider('cases')]
    public function it_throws_bridge_not_connected_without_a_connection(string $provider, string $model, string $method): void
    {
        $request = new AiRequestDTO(
            provider: $provider,
            model: $model,
            systemPrompt: 'sys',
            userPrompt: 'hello',
            teamId: $this->team->id,
        );

        try {
            app(LocalBridgeGateway::class)->{$method}($request);
            $this->fail('Expected BridgeNotConnectedException');
        } catch (BridgeNotConnectedException $e) {
            $this->assertInstanceOf(RuntimeException::class, $e);
            $this->assertStringContainsString(BridgeNotConnectedException::DOWNLOAD_URL, $e->getMessage());
            $this->assertStringContainsString('Team Settings', $e->getMessage());
        }
    }
}
