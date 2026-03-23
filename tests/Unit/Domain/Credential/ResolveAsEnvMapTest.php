<?php

namespace Tests\Unit\Domain\Credential;

use App\Domain\Agent\Models\Agent;
use App\Domain\Credential\Actions\ResolveProjectCredentialsAction;
use App\Domain\Credential\Enums\CredentialStatus;
use App\Domain\Credential\Enums\CredentialType;
use App\Domain\Credential\Models\Credential;
use App\Domain\Shared\Models\Team;
use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Models\Tool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolveAsEnvMapTest extends TestCase
{
    use RefreshDatabase;
    private ResolveProjectCredentialsAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = new ResolveProjectCredentialsAction;
    }

    public function test_returns_empty_array_when_agent_not_found(): void
    {
        $result = $this->action->resolveAsEnvMap('non-existent-uuid');

        $this->assertSame([], $result);
    }

    public function test_returns_empty_array_when_agent_has_no_tools_with_credentials(): void
    {
        $team = Team::factory()->create();
        $agent = Agent::factory()->for($team)->create();

        $result = $this->action->resolveAsEnvMap($agent->id);

        $this->assertSame([], $result);
    }

    public function test_returns_credential_env_vars_for_agent_tools(): void
    {
        $team = Team::factory()->create();
        $agent = Agent::factory()->for($team)->create();

        $credential = Credential::factory()->for($team)->create([
            'name' => 'Stripe API',
            'credential_type' => CredentialType::ApiToken,
            'status' => CredentialStatus::Active,
            'secret_data' => ['api_key' => 'sk_test_123'],
        ]);

        $tool = Tool::factory()->for($team)->create([
            'type' => ToolType::BuiltIn,
            'credential_id' => $credential->id,
            'status' => ToolStatus::Active,
        ]);

        $agent->tools()->attach($tool->id, ['priority' => 1, 'overrides' => []]);

        $result = $this->action->resolveAsEnvMap($agent->id);

        $this->assertArrayHasKey('CRED_STRIPE_API_API_KEY', $result);
        $this->assertSame('sk_test_123', $result['CRED_STRIPE_API_API_KEY']);
    }

    public function test_excludes_inactive_credentials(): void
    {
        $team = Team::factory()->create();
        $agent = Agent::factory()->for($team)->create();

        $credential = Credential::factory()->for($team)->create([
            'name' => 'Disabled Cred',
            'credential_type' => CredentialType::ApiToken,
            'status' => CredentialStatus::Disabled,
            'secret_data' => ['api_key' => 'secret'],
        ]);

        $tool = Tool::factory()->for($team)->create([
            'type' => ToolType::BuiltIn,
            'credential_id' => $credential->id,
            'status' => ToolStatus::Active,
        ]);

        $agent->tools()->attach($tool->id, ['priority' => 1, 'overrides' => []]);

        $result = $this->action->resolveAsEnvMap($agent->id);

        $this->assertSame([], $result);
    }

    public function test_normalises_credential_name_to_env_var_format(): void
    {
        $team = Team::factory()->create();
        $agent = Agent::factory()->for($team)->create();

        $credential = Credential::factory()->for($team)->create([
            'name' => 'My Special Service!',
            'credential_type' => CredentialType::ApiToken,
            'status' => CredentialStatus::Active,
            'secret_data' => ['token' => 'tok_abc'],
        ]);

        $tool = Tool::factory()->for($team)->create([
            'type' => ToolType::BuiltIn,
            'credential_id' => $credential->id,
            'status' => ToolStatus::Active,
        ]);

        $agent->tools()->attach($tool->id, ['priority' => 1, 'overrides' => []]);

        $result = $this->action->resolveAsEnvMap($agent->id);

        $this->assertArrayHasKey('CRED_MY_SPECIAL_SERVICE__TOKEN', $result);
        $this->assertSame('tok_abc', $result['CRED_MY_SPECIAL_SERVICE__TOKEN']);
    }
}
