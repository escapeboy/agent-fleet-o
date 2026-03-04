<?php

namespace Tests\Feature\Domain\Tool;

use App\Domain\Agent\Models\Agent;
use App\Domain\Credential\Enums\CredentialStatus;
use App\Domain\Credential\Models\Credential;
use App\Domain\Tool\Actions\CreateToolAction;
use App\Domain\Tool\Actions\ResolveAgentToolsAction;
use App\Domain\Tool\Actions\UpdateToolAction;
use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Models\Tool;
use App\Domain\Tool\Services\ToolTranslator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ToolCredentialResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_tool_action_stores_credential_id(): void
    {
        $credential = Credential::factory()->create();

        $tool = app(CreateToolAction::class)->execute(
            teamId: $credential->team_id,
            name: 'Test Tool',
            type: ToolType::McpHttp,
            transportConfig: ['url' => 'https://example.com/mcp', 'credential_env_var' => 'API_KEY'],
            credentialId: $credential->id,
        );

        $this->assertSame($credential->id, $tool->credential_id);
    }

    public function test_update_tool_action_sets_credential_id(): void
    {
        $tool = Tool::factory()->create();
        $credential = Credential::factory()->create(['team_id' => $tool->team_id]);

        $result = app(UpdateToolAction::class)->execute($tool, credentialId: $credential->id);

        $this->assertSame($credential->id, $result->credential_id);
    }

    public function test_update_tool_action_clears_credential_id(): void
    {
        $credential = Credential::factory()->create();
        $tool = Tool::factory()->create([
            'team_id' => $credential->team_id,
            'credential_id' => $credential->id,
        ]);

        $result = app(UpdateToolAction::class)->execute($tool, clearCredentialId: true);

        $this->assertNull($result->credential_id);
    }

    public function test_resolve_agent_tools_injects_inline_credential(): void
    {
        $agent = Agent::factory()->create();

        $tool = Tool::factory()->create([
            'team_id' => $agent->team_id,
            'type' => ToolType::McpStdio,
            'status' => ToolStatus::Active,
            'transport_config' => ['command' => 'echo', 'credential_env_var' => 'MY_TOKEN'],
            'credentials' => ['token' => 'inline-secret-value'],
            'tool_definitions' => [['name' => 'test', 'description' => 'test', 'input_schema' => ['type' => 'object', 'properties' => []]]],
        ]);

        $agent->tools()->attach($tool->id, ['priority' => 1]);

        $capturedConfig = null;
        $this->mock(ToolTranslator::class, function ($mock) use (&$capturedConfig) {
            $mock->shouldReceive('toPrismTools')
                ->once()
                ->andReturnUsing(function (Tool $t) use (&$capturedConfig) {
                    $capturedConfig = $t->transport_config;

                    return [];
                });
        });

        app(ResolveAgentToolsAction::class)->execute($agent);

        $this->assertSame('inline-secret-value', $capturedConfig['env']['MY_TOKEN'] ?? null);
    }

    public function test_resolve_agent_tools_injects_linked_credential(): void
    {
        $agent = Agent::factory()->create();

        $credential = Credential::factory()->create([
            'team_id' => $agent->team_id,
            'status' => CredentialStatus::Active,
            'secret_data' => ['api_key' => 'linked-api-secret'],
        ]);

        $tool = Tool::factory()->create([
            'team_id' => $agent->team_id,
            'type' => ToolType::McpStdio,
            'status' => ToolStatus::Active,
            'credential_id' => $credential->id,
            'transport_config' => ['command' => 'echo', 'credential_env_var' => 'API_KEY'],
            'credentials' => [],
            'tool_definitions' => [['name' => 'test', 'description' => 'test', 'input_schema' => ['type' => 'object', 'properties' => []]]],
        ]);

        $agent->tools()->attach($tool->id, ['priority' => 1]);

        $capturedConfig = null;
        $this->mock(ToolTranslator::class, function ($mock) use (&$capturedConfig) {
            $mock->shouldReceive('toPrismTools')
                ->once()
                ->andReturnUsing(function (Tool $t) use (&$capturedConfig) {
                    $capturedConfig = $t->transport_config;

                    return [];
                });
        });

        app(ResolveAgentToolsAction::class)->execute($agent);

        $this->assertSame('linked-api-secret', $capturedConfig['env']['API_KEY'] ?? null);
    }

    public function test_resolve_agent_tools_prefers_linked_credential_over_inline(): void
    {
        $agent = Agent::factory()->create();

        $credential = Credential::factory()->create([
            'team_id' => $agent->team_id,
            'status' => CredentialStatus::Active,
            'secret_data' => ['api_key' => 'linked-secret'],
        ]);

        $tool = Tool::factory()->create([
            'team_id' => $agent->team_id,
            'type' => ToolType::McpStdio,
            'status' => ToolStatus::Active,
            'credential_id' => $credential->id,
            'transport_config' => ['command' => 'echo', 'credential_env_var' => 'API_KEY'],
            'credentials' => ['api_key' => 'inline-secret'],
            'tool_definitions' => [['name' => 'test', 'description' => 'test', 'input_schema' => ['type' => 'object', 'properties' => []]]],
        ]);

        $agent->tools()->attach($tool->id, ['priority' => 1]);

        $capturedConfig = null;
        $this->mock(ToolTranslator::class, function ($mock) use (&$capturedConfig) {
            $mock->shouldReceive('toPrismTools')
                ->once()
                ->andReturnUsing(function (Tool $t) use (&$capturedConfig) {
                    $capturedConfig = $t->transport_config;

                    return [];
                });
        });

        app(ResolveAgentToolsAction::class)->execute($agent);

        $this->assertSame('linked-secret', $capturedConfig['env']['API_KEY'] ?? null);
    }

    public function test_resolve_agent_tools_skips_unusable_linked_credential(): void
    {
        $agent = Agent::factory()->create();

        $disabledCredential = Credential::factory()->create([
            'team_id' => $agent->team_id,
            'status' => CredentialStatus::Disabled,
            'secret_data' => ['api_key' => 'disabled-secret'],
        ]);

        $tool = Tool::factory()->create([
            'team_id' => $agent->team_id,
            'type' => ToolType::McpStdio,
            'status' => ToolStatus::Active,
            'credential_id' => $disabledCredential->id,
            'transport_config' => ['command' => 'echo', 'credential_env_var' => 'API_KEY'],
            'credentials' => [],
            'tool_definitions' => [['name' => 'test', 'description' => 'test', 'input_schema' => ['type' => 'object', 'properties' => []]]],
        ]);

        $agent->tools()->attach($tool->id, ['priority' => 1]);

        $capturedConfig = null;
        $this->mock(ToolTranslator::class, function ($mock) use (&$capturedConfig) {
            $mock->shouldReceive('toPrismTools')
                ->once()
                ->andReturnUsing(function (Tool $t) use (&$capturedConfig) {
                    $capturedConfig = $t->transport_config;

                    return [];
                });
        });

        app(ResolveAgentToolsAction::class)->execute($agent);

        // Disabled credential should not be injected
        $this->assertArrayNotHasKey('env', $capturedConfig);
    }
}
