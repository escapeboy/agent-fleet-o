<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Agent\Actions\CreateAgentPolicyAction;
use App\Domain\Agent\Actions\UpdateAgentPolicyAction;
use App\Domain\Shared\Models\Team;

class AgentPolicyApiTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The cloud credit/quota write-gate middleware hits Redis, which is a
        // Docker-only service (unreachable from the host test runner). It is
        // orthogonal to policy CRUD — skip it. No-op when base runs standalone
        // in CI, where these middleware aren't in the stack.
        $this->withoutMiddleware([
            'Cloud\Http\Middleware\CheckCreditsAvailable',
            'Cloud\Http\Middleware\CheckQuotaAvailable',
        ]);
    }

    public function test_store_creates_policy_with_first_version(): void
    {
        $this->actingAsApiUser();

        $response = $this->postJson('/api/v1/agent-policies', [
            'name' => 'Default',
            'rules' => ['risk_ceiling' => 'low'],
            'enabled' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Default')
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.current_version', 1)
            ->assertJsonPath('data.rules.risk_ceiling', 'low');
    }

    public function test_index_lists_only_team_policies(): void
    {
        $this->actingAsApiUser();
        app(CreateAgentPolicyAction::class)->execute(teamId: $this->team->id, name: 'Mine');

        $other = Team::create([
            'name' => 'Other', 'slug' => 'other-'.bin2hex(random_bytes(3)),
            'owner_id' => $this->user->id, 'settings' => [],
        ]);
        app(CreateAgentPolicyAction::class)->execute(teamId: $other->id, name: 'Theirs');

        $response = $this->getJson('/api/v1/agent-policies');

        $response->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Mine');
    }

    public function test_update_with_rules_mints_new_version(): void
    {
        $this->actingAsApiUser();
        $policy = app(CreateAgentPolicyAction::class)->execute(
            teamId: $this->team->id, name: 'P', rules: ['risk_ceiling' => 'low'],
        );

        $response = $this->putJson("/api/v1/agent-policies/{$policy->id}", [
            'rules' => ['risk_ceiling' => 'high'],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.current_version', 2)
            ->assertJsonPath('data.rules.risk_ceiling', 'high');
    }

    public function test_rollback_restores_prior_rules(): void
    {
        $this->actingAsApiUser();
        $policy = app(CreateAgentPolicyAction::class)->execute(
            teamId: $this->team->id, name: 'P', rules: ['risk_ceiling' => 'low'],
        );
        $v1Id = $policy->current_version_id;
        app(UpdateAgentPolicyAction::class)
            ->execute($policy, rules: ['risk_ceiling' => 'critical']);

        $response = $this->postJson("/api/v1/agent-policies/{$policy->id}/rollback", [
            'version_id' => $v1Id,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.current_version', 3)
            ->assertJsonPath('data.rules.risk_ceiling', 'low');
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/agent-policies')->assertUnauthorized();
    }
}
