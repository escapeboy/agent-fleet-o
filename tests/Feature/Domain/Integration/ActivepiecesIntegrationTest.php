<?php

namespace Tests\Feature\Domain\Integration;

use App\Domain\Integration\Actions\SyncActivepiecesToolsAction;
use App\Domain\Integration\Drivers\Activepieces\ActivepiecesIntegrationDriver;
use App\Domain\Integration\DTOs\ActivepiecesSyncResult;
use App\Domain\Integration\Enums\AuthType;
use App\Domain\Integration\Enums\IntegrationStatus;
use App\Domain\Integration\Models\Integration;
use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Services\SsrfGuard;
use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Models\Tool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ActivepiecesIntegrationTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Driver unit tests
    // -------------------------------------------------------------------------

    public function test_driver_key_is_activepieces(): void
    {
        $driver = new ActivepiecesIntegrationDriver;

        $this->assertSame('activepieces', $driver->key());
    }

    public function test_driver_auth_type_is_api_key(): void
    {
        $driver = new ActivepiecesIntegrationDriver;

        $this->assertSame(AuthType::ApiKey, $driver->authType());
    }

    public function test_driver_credential_schema_contains_base_url_and_api_key(): void
    {
        $driver = new ActivepiecesIntegrationDriver;
        $schema = $driver->credentialSchema();

        $this->assertArrayHasKey('base_url', $schema);
        $this->assertArrayHasKey('api_key', $schema);
        $this->assertTrue($schema['base_url']['required']);
        $this->assertTrue($schema['api_key']['required']);
    }

    public function test_driver_validate_credentials_returns_true_on_successful_api_response(): void
    {
        Http::fake([
            'https://ap.example.com/api/v1/pieces*' => Http::response([
                ['name' => 'openai', 'displayName' => 'OpenAI'],
            ], 200),
        ]);

        $driver = new ActivepiecesIntegrationDriver;

        $this->assertTrue($driver->validateCredentials([
            'base_url' => 'https://ap.example.com',
            'api_key' => 'test-key',
        ]));
    }

    public function test_driver_validate_credentials_returns_false_when_api_returns_error(): void
    {
        Http::fake([
            'https://ap.example.com/api/v1/pieces*' => Http::response('Unauthorized', 401),
        ]);

        $driver = new ActivepiecesIntegrationDriver;

        $this->assertFalse($driver->validateCredentials([
            'base_url' => 'https://ap.example.com',
            'api_key' => 'bad-key',
        ]));
    }

    public function test_driver_validate_credentials_returns_false_when_base_url_missing(): void
    {
        $driver = new ActivepiecesIntegrationDriver;

        $this->assertFalse($driver->validateCredentials(['api_key' => 'key']));
    }

    public function test_driver_ping_returns_healthy_result(): void
    {
        Http::fake([
            'https://ap.example.com/api/v1/pieces*' => Http::response([], 200),
        ]);

        $team = Team::factory()->create();

        $integration = Integration::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'driver' => 'activepieces',
            'name' => 'Test AP',
            'status' => IntegrationStatus::Active,
            'config' => ['base_url' => 'https://ap.example.com'],
            'meta' => [],
        ]);

        $driver = new ActivepiecesIntegrationDriver;
        $result = $driver->ping($integration);

        $this->assertTrue($result->healthy);
        $this->assertNotNull($result->latencyMs);
    }

    public function test_driver_poll_frequency_is_zero(): void
    {
        $driver = new ActivepiecesIntegrationDriver;

        $this->assertSame(0, $driver->pollFrequency());
    }

    public function test_driver_does_not_support_webhooks(): void
    {
        $driver = new ActivepiecesIntegrationDriver;

        $this->assertFalse($driver->supportsWebhooks());
    }

    // -------------------------------------------------------------------------
    // SyncActivepiecesToolsAction tests
    // -------------------------------------------------------------------------

    public function test_sync_action_upserts_pieces_as_tools(): void
    {
        Http::fake([
            'https://ap.example.com/api/v1/pieces*' => Http::response([
                ['name' => 'openai', 'displayName' => 'OpenAI', 'description' => 'OpenAI piece'],
                ['name' => 'gmail', 'displayName' => 'Gmail', 'description' => 'Gmail piece'],
            ], 200),
        ]);

        $team = Team::factory()->create();

        $integration = Integration::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'driver' => 'activepieces',
            'name' => 'Test AP',
            'status' => IntegrationStatus::Active,
            'config' => ['base_url' => 'https://ap.example.com'],
            'meta' => [],
            'credential_id' => null,
        ]);

        // Fake the SSRF guard so it does not reject our fake URL.
        $this->mock(SsrfGuard::class, function ($mock) {
            $mock->shouldReceive('assertPublicUrl')->andReturn(null);
        });

        $action = app(SyncActivepiecesToolsAction::class);
        $result = $action->execute($integration);

        $this->assertInstanceOf(ActivepiecesSyncResult::class, $result);
        $this->assertSame(2, $result->upserted);
        $this->assertSame(0, $result->deactivated);

        // Verify tool records were created.
        $tools = Tool::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('type', ToolType::McpHttp)
            ->whereRaw("settings->>'activepieces_integration_id' = ?", [$integration->id])
            ->get();

        $this->assertCount(2, $tools);
        $this->assertTrue($tools->every(fn (Tool $t) => $t->status === ToolStatus::Active));
    }

    public function test_sync_action_deactivates_stale_pieces(): void
    {
        Http::fake([
            'https://ap.example.com/api/v1/pieces*' => Http::response([
                ['name' => 'openai', 'displayName' => 'OpenAI', 'description' => 'OpenAI piece'],
            ], 200),
        ]);

        $team = Team::factory()->create();

        $integration = Integration::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'driver' => 'activepieces',
            'name' => 'Test AP',
            'status' => IntegrationStatus::Active,
            'config' => ['base_url' => 'https://ap.example.com'],
            'meta' => [],
            'credential_id' => null,
        ]);

        // Pre-create a stale tool that was synced previously.
        Tool::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'name' => 'Old Piece',
            'slug' => 'ap-old-piece',
            'type' => ToolType::McpHttp,
            'status' => ToolStatus::Active,
            'settings' => [
                'activepieces_piece_name' => 'old_piece',
                'activepieces_integration_id' => $integration->id,
                'last_synced_at' => now()->subHour()->toIso8601String(),
            ],
            'transport_config' => ['url' => 'https://ap.example.com/api/mcp/old_piece'],
        ]);

        $this->mock(SsrfGuard::class, function ($mock) {
            $mock->shouldReceive('assertPublicUrl')->andReturn(null);
        });

        $action = app(SyncActivepiecesToolsAction::class);
        $result = $action->execute($integration);

        $this->assertSame(1, $result->upserted);
        $this->assertSame(1, $result->deactivated);

        $stale = Tool::withoutGlobalScopes()->where('slug', 'ap-old-piece')->first();
        $this->assertSame(ToolStatus::Disabled, $stale->status);
    }

    public function test_sync_action_returns_cached_result_on_repeated_call(): void
    {
        Http::fake([
            'https://ap.example.com/api/v1/pieces*' => Http::response([
                ['name' => 'openai', 'displayName' => 'OpenAI'],
            ], 200),
        ]);

        $team = Team::factory()->create();

        $integration = Integration::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'driver' => 'activepieces',
            'name' => 'Test AP',
            'status' => IntegrationStatus::Active,
            'config' => ['base_url' => 'https://ap.example.com'],
            'meta' => [],
            'credential_id' => null,
        ]);

        $this->mock(SsrfGuard::class, function ($mock) {
            $mock->shouldReceive('assertPublicUrl')->andReturn(null);
        });

        $action = app(SyncActivepiecesToolsAction::class);

        $first = $action->execute($integration);
        $second = $action->execute($integration);

        // Should be the same cached object.
        $this->assertEquals($first->upserted, $second->upserted);
        // HTTP should only have been called once.
        Http::assertSentCount(1);
    }

    public function test_sync_action_throws_when_api_returns_error(): void
    {
        Http::fake([
            'https://ap.example.com/api/v1/pieces*' => Http::response('Forbidden', 403),
        ]);

        $team = Team::factory()->create();

        $integration = Integration::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'driver' => 'activepieces',
            'name' => 'Test AP',
            'status' => IntegrationStatus::Active,
            'config' => ['base_url' => 'https://ap.example.com'],
            'meta' => [],
            'credential_id' => null,
        ]);

        // Clear any cached result.
        Cache::forget("activepieces_sync_{$integration->id}");

        $this->mock(SsrfGuard::class, function ($mock) {
            $mock->shouldReceive('assertPublicUrl')->andReturn(null);
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/HTTP 403/');

        app(SyncActivepiecesToolsAction::class)->execute($integration);
    }
}
