<?php

namespace Tests\Feature\Infrastructure\AI;

use App\Domain\Bridge\Enums\BridgeConnectionStatus;
use App\Domain\Bridge\Models\BridgeConnection;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Exceptions\BridgeModelNotAvailableException;
use App\Infrastructure\AI\Gateways\HttpBridgeGateway;
use App\Infrastructure\AI\Gateways\LocalBridgeGateway;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BridgeLlmModelGuardTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Bridge Guard Team',
            'slug' => 'bridge-guard-team',
            'owner_id' => $user->id,
            'plan' => 'pro',
            'settings' => [],
        ]);
    }

    private function makeConnection(array $llmEndpoints, ?string $endpointUrl = null): BridgeConnection
    {
        return BridgeConnection::create([
            'team_id' => $this->team->id,
            'session_id' => 'relay-guard',
            'priority' => 1,
            'status' => BridgeConnectionStatus::Connected,
            'endpoints' => ['agents' => [], 'llm_endpoints' => $llmEndpoints],
            'endpoint_url' => $endpointUrl,
            'connected_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    private function request(string $model): AiRequestDTO
    {
        return new AiRequestDTO(
            provider: 'bridge_llm',
            model: $model,
            systemPrompt: 'sys',
            userPrompt: 'hello',
            teamId: $this->team->id,
        );
    }

    private function fakeSse(): void
    {
        Http::fake([
            '*' => Http::response("data: {\"chunk\":\"ok\",\"done\":true}\n\n", 200, ['Content-Type' => 'text/event-stream']),
        ]);
    }

    #[Test]
    public function relay_gateway_rejects_unknown_model_before_dispatch(): void
    {
        $this->makeConnection([
            ['name' => 'ollama', 'base_url' => 'http://127.0.0.1:11434', 'online' => true, 'models' => ['llama3.2', 'qwen2.5']],
            ['name' => 'other', 'base_url' => 'http://127.0.0.1:1234', 'online' => false, 'models' => ['offline-model']],
        ]);

        Redis::shouldReceive('connection')->never();
        Redis::shouldReceive('rpush')->never();

        try {
            app(LocalBridgeGateway::class)->complete($this->request('gpt-unknown'));
            $this->fail('Expected BridgeModelNotAvailableException');
        } catch (BridgeModelNotAvailableException $e) {
            $this->assertStringContainsString('"gpt-unknown"', $e->getMessage());
            $this->assertStringContainsString('llama3.2, qwen2.5', $e->getMessage());
            $this->assertStringNotContainsString('offline-model', $e->getMessage());
            $this->assertSame(['llama3.2', 'qwen2.5'], $e->available);
        }
    }

    #[Test]
    public function http_gateway_rejects_unknown_model_before_dispatch(): void
    {
        $this->makeConnection([
            ['name' => 'ollama', 'base_url' => 'http://127.0.0.1:11434', 'online' => true, 'models' => ['llama3.2']],
        ], 'https://bridge.example.test');
        $this->fakeSse();

        try {
            app(HttpBridgeGateway::class)->complete($this->request('gpt-unknown'));
            $this->fail('Expected BridgeModelNotAvailableException');
        } catch (BridgeModelNotAvailableException $e) {
            $this->assertStringContainsString('"gpt-unknown"', $e->getMessage());
            $this->assertStringContainsString('Available models: llama3.2', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function check_is_skipped_when_an_online_endpoint_reports_no_models(): void
    {
        $this->makeConnection([
            ['name' => 'ollama', 'base_url' => 'http://127.0.0.1:11434', 'online' => true, 'models' => ['llama3.2']],
            ['name' => 'empty', 'base_url' => 'http://127.0.0.1:1234', 'online' => true, 'models' => []],
            ['name' => 'missing', 'base_url' => 'http://127.0.0.1:8000', 'online' => true],
        ], 'https://bridge.example.test');
        $this->fakeSse();

        $response = app(HttpBridgeGateway::class)->complete($this->request('gpt-unknown'));

        $this->assertSame('ok', $response->content);
        Http::assertSent(fn (Request $r) => $r['model'] === 'gpt-unknown');
    }

    #[Test]
    public function known_model_is_dispatched(): void
    {
        $this->makeConnection([
            ['name' => 'ollama', 'base_url' => 'http://127.0.0.1:11434', 'online' => true, 'models' => ['llama3.2']],
        ], 'https://bridge.example.test');
        $this->fakeSse();

        $response = app(HttpBridgeGateway::class)->complete($this->request('llama3.2'));

        $this->assertSame('ok', $response->content);
        Http::assertSentCount(1);
    }
}
