<?php

namespace Tests\Feature\Mcp;

use App\Domain\Shared\Models\Team;
use App\Mcp\Tools\Compact\CompactTool;
use App\Mcp\Tools\Shared\McpToolCatalogTool;
use App\Mcp\Tools\Shared\McpToolPreferencesTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Tests\TestCase;

class McpToolPreferencesTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team-mcp',
            'owner_id' => $user->id,
            'settings' => [],
        ]);

        app()->instance('mcp.team_id', $this->team->id);
    }

    private function request(array $args): Request
    {
        return new Request($args);
    }

    private function decode(Response $response): array
    {
        return json_decode((string) $response->content(), true);
    }

    private function collectGeneratorResponses(\Generator $generator): array
    {
        $responses = [];

        foreach ($generator as $response) {
            $responses[] = $response;
        }

        return $responses;
    }

    // -------------------------------------------------------------------------
    // shouldRegister() — CompactTool filtering
    // -------------------------------------------------------------------------

    public function test_should_register_returns_true_when_no_team_bound(): void
    {
        app()->forgetInstance('mcp.team_id');

        $tool = $this->createCompactToolStub('agent_manage');

        $this->assertTrue($tool->shouldRegister());
    }

    public function test_should_register_returns_true_when_no_mcp_settings(): void
    {
        $tool = $this->createCompactToolStub('agent_manage');

        $this->assertTrue($tool->shouldRegister());
    }

    public function test_should_register_filters_by_profile(): void
    {
        $this->team->update(['settings' => ['mcp_tools' => ['profile' => 'essential']]]);
        // Clear cache
        $cacheKey = "mcp.team_mcp_settings.{$this->team->id}";
        if (app()->bound($cacheKey)) {
            app()->forgetInstance($cacheKey);
        }

        $included = $this->createCompactToolStub('agent_manage');
        $excluded = $this->createCompactToolStub('email_manage');

        $this->assertTrue($included->shouldRegister());
        $this->assertFalse($excluded->shouldRegister());
    }

    public function test_should_register_filters_by_custom_enabled_list(): void
    {
        $this->team->update(['settings' => ['mcp_tools' => ['enabled' => ['agent_manage', 'project_manage']]]]);
        $cacheKey = "mcp.team_mcp_settings.{$this->team->id}";
        if (app()->bound($cacheKey)) {
            app()->forgetInstance($cacheKey);
        }

        $included = $this->createCompactToolStub('agent_manage');
        $excluded = $this->createCompactToolStub('budget_manage');

        $this->assertTrue($included->shouldRegister());
        $this->assertFalse($excluded->shouldRegister());
    }

    public function test_should_register_full_profile_allows_all(): void
    {
        $this->team->update(['settings' => ['mcp_tools' => ['profile' => 'full']]]);
        $cacheKey = "mcp.team_mcp_settings.{$this->team->id}";
        if (app()->bound($cacheKey)) {
            app()->forgetInstance($cacheKey);
        }

        $tool = $this->createCompactToolStub('admin_manage');

        // full profile resolves to null in config, so all tools pass
        $this->assertTrue($tool->shouldRegister());
    }

    // -------------------------------------------------------------------------
    // McpToolCatalogTool
    // -------------------------------------------------------------------------

    public function test_catalog_returns_grouped_tools_with_status(): void
    {
        $tool = app(McpToolCatalogTool::class);
        $response = $tool->handle($this->request([]));

        $data = $this->decode($response);

        $this->assertArrayHasKey('groups', $data);
        $this->assertArrayHasKey('active_profile', $data);
        $this->assertEquals('full', $data['active_profile']);

        // All tools should be enabled when no settings
        foreach ($data['groups'] as $group) {
            foreach ($group['tools'] as $toolInfo) {
                $this->assertTrue($toolInfo['enabled']);
            }
        }
    }

    public function test_catalog_reflects_profile_restrictions(): void
    {
        $this->team->update(['settings' => ['mcp_tools' => ['profile' => 'essential']]]);

        $tool = app(McpToolCatalogTool::class);
        $response = $tool->handle($this->request([]));
        $data = $this->decode($response);

        $this->assertEquals('essential', $data['active_profile']);

        // email_manage is not in essential, should be disabled
        $specializedTools = $data['groups']['specialized']['tools'] ?? [];
        $this->assertFalse($specializedTools['email_manage']['enabled']);

        // agent_manage is in essential, should be enabled
        $coreTools = $data['groups']['core']['tools'] ?? [];
        $this->assertTrue($coreTools['agent_manage']['enabled']);
    }

    // -------------------------------------------------------------------------
    // McpToolPreferencesTool
    // -------------------------------------------------------------------------

    public function test_preferences_sets_profile(): void
    {
        $tool = app(McpToolPreferencesTool::class);
        $responses = $this->collectGeneratorResponses(
            $tool->handle($this->request(['profile' => 'essential'])),
        );

        // Should have notification + text response
        $this->assertCount(2, $responses);

        $textResponse = $responses[1];
        $data = $this->decode($textResponse);
        $this->assertTrue($data['success']);

        $this->team->refresh();
        $this->assertEquals('essential', $this->team->settings['mcp_tools']['profile']);
    }

    public function test_preferences_sets_custom_enabled_list(): void
    {
        $tool = app(McpToolPreferencesTool::class);
        $responses = $this->collectGeneratorResponses(
            $tool->handle($this->request(['enabled' => ['agent_manage', 'project_manage']])),
        );

        $textResponse = $responses[1];
        $data = $this->decode($textResponse);
        $this->assertTrue($data['success']);

        $this->team->refresh();
        $this->assertEquals(['agent_manage', 'project_manage'], $this->team->settings['mcp_tools']['enabled']);
    }

    public function test_preferences_rejects_both_profile_and_enabled(): void
    {
        $tool = app(McpToolPreferencesTool::class);
        $responses = $this->collectGeneratorResponses(
            $tool->handle($this->request(['profile' => 'essential', 'enabled' => ['agent_manage']])),
        );

        $this->assertTrue($responses[0]->isError());
    }

    public function test_preferences_rejects_unknown_tool_names(): void
    {
        $tool = app(McpToolPreferencesTool::class);
        $responses = $this->collectGeneratorResponses(
            $tool->handle($this->request(['enabled' => ['agent_manage', 'nonexistent_tool']])),
        );

        $this->assertTrue($responses[0]->isError());
        $this->assertStringContainsString('nonexistent_tool', (string) $responses[0]->content());
    }

    public function test_preferences_full_profile_clears_settings(): void
    {
        // First set a profile
        $this->team->update(['settings' => ['mcp_tools' => ['profile' => 'essential']]]);

        $tool = app(McpToolPreferencesTool::class);
        $responses = $this->collectGeneratorResponses(
            $tool->handle($this->request(['profile' => 'full'])),
        );

        $textResponse = $responses[1];
        $data = $this->decode($textResponse);
        $this->assertTrue($data['success']);

        $this->team->refresh();
        $this->assertNull($this->team->settings['mcp_tools'] ?? null);
    }

    public function test_preferences_emits_list_changed_notification(): void
    {
        $tool = app(McpToolPreferencesTool::class);
        $responses = $this->collectGeneratorResponses(
            $tool->handle($this->request(['profile' => 'standard'])),
        );

        // First response should be the notification
        $this->assertTrue($responses[0]->isNotification());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function createCompactToolStub(string $name): CompactTool
    {
        return new class($name) extends CompactTool
        {
            protected string $name;

            public function __construct(string $name)
            {
                $this->name = $name;
            }

            protected function toolMap(): array
            {
                return [];
            }
        };
    }
}
