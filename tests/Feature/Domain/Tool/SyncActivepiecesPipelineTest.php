<?php

namespace Tests\Feature\Domain\Tool;

use App\Domain\Credential\Enums\CredentialType;
use App\Domain\Credential\Models\Credential;
use App\Domain\Integration\Actions\SyncActivepiecesToolsAction;
use App\Domain\Integration\Models\Integration;
use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Services\SsrfGuard;
use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Models\Tool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncActivepiecesPipelineTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private Integration $integration;

    private SyncActivepiecesToolsAction $action;

    private string $baseUrl = 'https://activepieces.example.com';

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();

        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team',
            'owner_id' => $user->id,
            'settings' => [],
        ]);

        $user->update(['current_team_id' => $this->team->id]);

        // Create a Credential with the Activepieces base_url + api_key.
        $credential = Credential::withoutGlobalScopes()->create([
            'team_id' => $this->team->id,
            'name' => 'Activepieces API Key',
            'slug' => 'activepieces-api-key',
            'credential_type' => CredentialType::ApiToken,
            'status' => 'active',
            'secret_data' => [
                'base_url' => $this->baseUrl,
                'api_key' => 'test-api-key-123',
            ],
        ]);

        $this->integration = Integration::withoutGlobalScopes()->create([
            'team_id' => $this->team->id,
            'driver' => 'activepieces',
            'name' => 'My Activepieces',
            'credential_id' => $credential->id,
            'status' => 'active',
            'config' => [],
            'meta' => [],
        ]);

        // Bypass DNS resolution in the SsrfGuard so tests can use example.com URLs.
        // createMock stubs all methods to do nothing (no-op for void methods).
        $mockSsrfGuard = $this->createMock(SsrfGuard::class);
        $this->app->instance(SsrfGuard::class, $mockSsrfGuard);

        $this->action = app(SyncActivepiecesToolsAction::class);

        // Clear any cached results between tests.
        Cache::flush();
    }

    /**
     * Build a minimal Activepieces piece payload.
     */
    private function makePiece(string $name, string $displayName, string $description = ''): array
    {
        return [
            'name' => $name,
            'displayName' => $displayName,
            'description' => $description ?: "Automation piece: {$displayName}",
            'version' => '0.1.0',
        ];
    }

    public function test_full_sync_creates_tool_records_for_each_piece(): void
    {
        Http::fake([
            "{$this->baseUrl}/api/v1/pieces*" => Http::response([
                $this->makePiece('@activepieces/piece-slack', 'Slack'),
                $this->makePiece('@activepieces/piece-github', 'GitHub'),
                $this->makePiece('@activepieces/piece-stripe', 'Stripe'),
            ]),
        ]);

        $result = $this->action->execute($this->integration);

        $this->assertEquals(3, $result->upserted);
        $this->assertEquals(0, $result->deactivated);

        $tools = Tool::withoutGlobalScopes()
            ->where('team_id', $this->team->id)
            ->get();

        $this->assertCount(3, $tools);

        $slackTool = $tools->firstWhere('name', 'Slack');
        $this->assertNotNull($slackTool);
        $this->assertEquals(ToolType::McpHttp, $slackTool->type);
        $this->assertEquals(ToolStatus::Active, $slackTool->status);
        $this->assertEquals(
            "{$this->baseUrl}/api/mcp/@activepieces/piece-slack",
            $slackTool->transport_config['url'],
        );
        $this->assertEquals('@activepieces/piece-slack', $slackTool->settings['activepieces_piece_name']);
        $this->assertEquals((string) $this->integration->getKey(), $slackTool->settings['activepieces_integration_id']);
        $this->assertNotEmpty($slackTool->settings['last_synced_at']);
    }

    public function test_partial_deactivation_when_piece_removed_from_api_response(): void
    {
        // Register both API responses as a sequence so each call consumes the next one.
        Http::fake([
            "{$this->baseUrl}/api/v1/pieces*" => Http::sequence()
                ->push([
                    $this->makePiece('@activepieces/piece-slack', 'Slack'),
                    $this->makePiece('@activepieces/piece-github', 'GitHub'),
                    $this->makePiece('@activepieces/piece-stripe', 'Stripe'),
                ])
                ->push([
                    $this->makePiece('@activepieces/piece-slack', 'Slack'),
                    $this->makePiece('@activepieces/piece-github', 'GitHub'),
                ]),
        ]);

        // First sync: three pieces.
        $this->action->execute($this->integration);
        Cache::flush();

        // Second sync: Stripe removed from catalogue.
        $result = $this->action->execute($this->integration);

        $this->assertEquals(2, $result->upserted);
        $this->assertEquals(1, $result->deactivated);

        // Stripe tool should now be disabled.
        $stripeTool = Tool::withoutGlobalScopes()
            ->where('team_id', $this->team->id)
            ->where('slug', 'ap-at-activepiecespiece-stripe')
            ->first();

        $this->assertNotNull($stripeTool);
        $this->assertEquals(ToolStatus::Disabled, $stripeTool->status);

        // Other tools remain active.
        $this->assertEquals(
            2,
            Tool::withoutGlobalScopes()
                ->where('team_id', $this->team->id)
                ->where('status', ToolStatus::Active)
                ->count(),
        );
    }

    public function test_piece_filter_from_integration_config_limits_synced_tools(): void
    {
        // Update integration config to only accept specific piece names.
        $this->integration->update([
            'config' => [
                'piece_filter' => ['@activepieces/piece-slack', '@activepieces/piece-github'],
            ],
        ]);

        Http::fake([
            "{$this->baseUrl}/api/v1/pieces*" => Http::response([
                $this->makePiece('@activepieces/piece-slack', 'Slack'),
                $this->makePiece('@activepieces/piece-github', 'GitHub'),
                $this->makePiece('@activepieces/piece-stripe', 'Stripe'),
                $this->makePiece('@activepieces/piece-hubspot', 'HubSpot'),
            ]),
        ]);

        $this->integration->refresh();

        $result = $this->action->execute($this->integration);

        // Only the 2 pieces in the allowlist should be synced.
        $this->assertEquals(2, $result->upserted);

        $toolNames = Tool::withoutGlobalScopes()
            ->where('team_id', $this->team->id)
            ->pluck('name')
            ->sort()
            ->values()
            ->toArray();

        $this->assertEquals(['GitHub', 'Slack'], $toolNames);
    }

    public function test_http_401_throws_runtime_exception(): void
    {
        Http::fake([
            "{$this->baseUrl}/api/v1/pieces*" => Http::response(
                ['message' => 'Unauthorized'],
                401,
            ),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/401/');

        $this->action->execute($this->integration);
    }

    public function test_empty_piece_list_returns_empty_result(): void
    {
        Http::fake([
            "{$this->baseUrl}/api/v1/pieces*" => Http::response([]),
        ]);

        $result = $this->action->execute($this->integration);

        $this->assertEquals(0, $result->upserted);
        $this->assertEquals(0, $result->deactivated);

        $this->assertEquals(
            0,
            Tool::withoutGlobalScopes()->where('team_id', $this->team->id)->count(),
        );
    }

    public function test_re_sync_updates_existing_tools_rather_than_duplicating(): void
    {
        Http::fake([
            "{$this->baseUrl}/api/v1/pieces*" => Http::sequence()
                ->push([$this->makePiece('@activepieces/piece-slack', 'Slack', 'Old description')])
                ->push([$this->makePiece('@activepieces/piece-slack', 'Slack Updated', 'New description')]),
        ]);

        $this->action->execute($this->integration);
        Cache::flush();

        $result = $this->action->execute($this->integration);

        $this->assertEquals(1, $result->upserted);

        // Only one tool should exist (no duplicate).
        $this->assertEquals(
            1,
            Tool::withoutGlobalScopes()->where('team_id', $this->team->id)->count(),
        );

        $tool = Tool::withoutGlobalScopes()->where('team_id', $this->team->id)->first();
        $this->assertEquals('Slack Updated', $tool->name);
    }

    public function test_results_are_cached_for_five_minutes(): void
    {
        Http::fake([
            "{$this->baseUrl}/api/v1/pieces*" => Http::response([
                $this->makePiece('@activepieces/piece-slack', 'Slack'),
            ]),
        ]);

        // First call hits the API.
        $result1 = $this->action->execute($this->integration);

        // Second call should return cached result without hitting API again.
        Http::fake([
            "{$this->baseUrl}/api/v1/pieces*" => Http::response([], 500),
        ]);

        $result2 = $this->action->execute($this->integration);

        $this->assertEquals($result1->upserted, $result2->upserted);
        $this->assertEquals($result1->message, $result2->message);
    }
}
