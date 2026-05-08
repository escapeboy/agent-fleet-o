<?php

namespace Tests\Feature\Domain\Tool;

use App\Domain\Credential\Enums\CredentialType;
use App\Domain\Credential\Models\Credential;
use App\Domain\Shared\Models\Team;
use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Models\Tool;
use App\Domain\Tool\Services\McpStdioClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Verifies the `credential:<field>` placeholder substitution wired into
 * McpStdioClient::resolveCredentialPlaceholders. This is what makes the
 * 1Password MCP tool actually launch with a token (the seed only declares
 * the placeholder, the real token comes from the team's linked credential).
 */
class McpStdioClientCredentialPlaceholderTest extends TestCase
{
    use RefreshDatabase;

    public function test_placeholder_resolves_to_linked_credential_secret_field(): void
    {
        $team = Team::factory()->create();
        $credential = Credential::factory()->create([
            'team_id' => $team->id,
            'credential_type' => CredentialType::OnePasswordServiceAccount,
            'secret_data' => ['service_account_token' => 'ops_realtokenvalue1234567890123456'],
        ]);
        $tool = Tool::factory()->create([
            'team_id' => $team->id,
            'type' => ToolType::McpStdio,
            'status' => ToolStatus::Active,
            'credential_id' => $credential->id,
            'transport_config' => [
                'command' => 'true',
                'args' => [],
                'env' => ['OP_SERVICE_ACCOUNT_TOKEN' => 'credential:service_account_token'],
            ],
        ]);

        $resolved = $this->invokeResolver($tool, [
            'OP_SERVICE_ACCOUNT_TOKEN' => 'credential:service_account_token',
            'OTHER_VAR' => 'literal-value',
        ]);

        $this->assertSame('ops_realtokenvalue1234567890123456', $resolved['OP_SERVICE_ACCOUNT_TOKEN']);
        $this->assertSame('literal-value', $resolved['OTHER_VAR']);
    }

    public function test_placeholder_yields_empty_string_when_tool_has_no_linked_credential(): void
    {
        $team = Team::factory()->create();
        $tool = Tool::factory()->create([
            'team_id' => $team->id,
            'type' => ToolType::McpStdio,
            'status' => ToolStatus::Active,
            'credential_id' => null,
            'transport_config' => [
                'command' => 'true',
                'args' => [],
                'env' => ['OP_SERVICE_ACCOUNT_TOKEN' => 'credential:service_account_token'],
            ],
        ]);

        $resolved = $this->invokeResolver($tool, [
            'OP_SERVICE_ACCOUNT_TOKEN' => 'credential:service_account_token',
        ]);

        $this->assertSame('', $resolved['OP_SERVICE_ACCOUNT_TOKEN']);
    }

    public function test_non_placeholder_values_pass_through_unchanged(): void
    {
        $team = Team::factory()->create();
        $tool = Tool::factory()->create([
            'team_id' => $team->id,
            'type' => ToolType::McpStdio,
            'status' => ToolStatus::Active,
            'credential_id' => null,
            'transport_config' => [
                'command' => 'true',
                'args' => [],
                'env' => [
                    'PLAIN_VALUE' => 'hello',
                    'NUMBER' => '42',
                    'EMPTY' => '',
                ],
            ],
        ]);

        $resolved = $this->invokeResolver($tool, [
            'PLAIN_VALUE' => 'hello',
            'NUMBER' => '42',
            'EMPTY' => '',
        ]);

        $this->assertSame(['PLAIN_VALUE' => 'hello', 'NUMBER' => '42', 'EMPTY' => ''], $resolved);
    }

    /**
     * @param  array<string, string>  $env
     * @return array<string, string>
     */
    private function invokeResolver(Tool $tool, array $env): array
    {
        $client = $this->app->make(McpStdioClient::class);
        $method = new ReflectionMethod($client, 'resolveCredentialPlaceholders');
        $method->setAccessible(true);

        return $method->invoke($client, $tool, $env);
    }
}
