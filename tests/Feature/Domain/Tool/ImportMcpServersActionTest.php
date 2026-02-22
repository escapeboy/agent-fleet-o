<?php

namespace Tests\Feature\Domain\Tool;

use App\Domain\Shared\Models\Team;
use App\Domain\Tool\Actions\ImportMcpServersAction;
use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Models\Tool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportMcpServersActionTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private ImportMcpServersAction $action;

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

        $this->action = app(ImportMcpServersAction::class);
    }

    /**
     * Use mcp_http type for servers — works in both community and cloud editions.
     */
    private function makeServer(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Test Server',
            'slug' => 'test-server-cursor',
            'source' => 'Cursor',
            'type' => 'mcp_http',
            'transport_config' => ['url' => 'https://mcp.example.com/sse'],
            'credentials' => [],
            'disabled' => false,
            'warnings' => [],
        ], $overrides);
    }

    public function test_imports_single_server(): void
    {
        $result = $this->action->execute(
            teamId: $this->team->id,
            servers: [$this->makeServer()],
        );

        $this->assertEquals(1, $result->imported);
        $this->assertEquals(0, $result->skipped);
        $this->assertEquals(0, $result->failed);
        $this->assertEquals(1, $result->total());

        $tool = Tool::withoutGlobalScopes()->where('team_id', $this->team->id)->first();
        $this->assertNotNull($tool);
        $this->assertEquals('Test Server', $tool->name);
        $this->assertEquals('mcp_http', $tool->type->value);
    }

    public function test_imports_multiple_servers(): void
    {
        $servers = [
            $this->makeServer(['name' => 'Server A', 'slug' => 'server-a-cursor']),
            $this->makeServer(['name' => 'Server B', 'slug' => 'server-b-cursor']),
        ];

        $result = $this->action->execute(
            teamId: $this->team->id,
            servers: $servers,
        );

        $this->assertEquals(2, $result->imported);
        $this->assertEquals(2, Tool::withoutGlobalScopes()->where('team_id', $this->team->id)->count());
    }

    public function test_skips_existing_servers_by_slug(): void
    {
        Tool::withoutGlobalScopes()->create([
            'team_id' => $this->team->id,
            'name' => 'Existing Tool',
            'slug' => 'test-server-cursor',
            'type' => 'mcp_http',
            'status' => 'active',
            'transport_config' => ['url' => 'https://example.com'],
            'credentials' => [],
            'tool_definitions' => [],
            'settings' => [],
        ]);

        $result = $this->action->execute(
            teamId: $this->team->id,
            servers: [$this->makeServer()],
            skipExisting: true,
        );

        $this->assertEquals(0, $result->imported);
        $this->assertEquals(1, $result->skipped);
        $this->assertEquals('Already exists', $result->details[0]['reason']);
    }

    public function test_skips_disabled_servers_by_default(): void
    {
        $result = $this->action->execute(
            teamId: $this->team->id,
            servers: [$this->makeServer(['disabled' => true])],
        );

        $this->assertEquals(0, $result->imported);
        $this->assertEquals(1, $result->skipped);
        $this->assertEquals('Disabled in source config', $result->details[0]['reason']);
    }

    public function test_imports_disabled_servers_when_flag_set(): void
    {
        $result = $this->action->execute(
            teamId: $this->team->id,
            servers: [$this->makeServer(['disabled' => true])],
            importDisabled: true,
        );

        $this->assertEquals(1, $result->imported);
    }

    public function test_sets_disabled_status_for_servers_with_warnings(): void
    {
        $result = $this->action->execute(
            teamId: $this->team->id,
            servers: [$this->makeServer(['warnings' => ['URL is unreachable']])],
        );

        $this->assertEquals(1, $result->imported);

        $tool = Tool::withoutGlobalScopes()->where('team_id', $this->team->id)->first();
        $this->assertEquals(ToolStatus::Disabled, $tool->status);
    }

    public function test_tracks_credential_placeholders(): void
    {
        $result = $this->action->execute(
            teamId: $this->team->id,
            servers: [
                $this->makeServer([
                    'name' => 'With Creds',
                    'slug' => 'with-creds-cursor',
                    'credentials' => ['api_key' => 'Bearer secret'],
                ]),
                $this->makeServer([
                    'name' => 'No Creds',
                    'slug' => 'no-creds-cursor',
                    'credentials' => [],
                ]),
            ],
        );

        $this->assertTrue($result->hasCredentialPlaceholders());
        $this->assertEquals(1, $result->credentialCount());
    }

    public function test_stores_source_metadata_in_settings(): void
    {
        $this->action->execute(
            teamId: $this->team->id,
            servers: [$this->makeServer()],
        );

        $tool = Tool::withoutGlobalScopes()->where('team_id', $this->team->id)->first();
        $this->assertNotNull($tool);
        $this->assertEquals('Cursor', $tool->settings['source_ide']);
        $this->assertNotEmpty($tool->settings['imported_at']);
    }

    public function test_import_result_dto_methods(): void
    {
        $result = $this->action->execute(
            teamId: $this->team->id,
            servers: [
                $this->makeServer(['name' => 'Active', 'slug' => 'active-cursor']),
                $this->makeServer(['name' => 'Disabled', 'slug' => 'disabled-cursor', 'disabled' => true]),
            ],
        );

        $this->assertEquals(2, $result->total());
        $this->assertEquals(1, $result->imported);
        $this->assertEquals(1, $result->skipped);
    }
}
