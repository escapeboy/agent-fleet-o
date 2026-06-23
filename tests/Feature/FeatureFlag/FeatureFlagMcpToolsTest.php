<?php

namespace Tests\Feature\FeatureFlag;

use App\Domain\FeatureFlag\Services\FeatureFlagService;
use App\Domain\Shared\Models\Team;
use App\Mcp\Tools\FeatureFlag\FeatureListTool;
use App\Mcp\Tools\FeatureFlag\FeatureRolloutTool;
use App\Mcp\Tools\FeatureFlag\FeatureStatusTool;
use App\Mcp\Tools\FeatureFlag\FeatureToggleTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Tests\TestCase;

class FeatureFlagMcpToolsTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        config(['feature_flags.runtime_enabled' => true]);

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'MCP Team',
            'slug' => 'ff-mcp-'.uniqid(),
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        app()->instance('mcp.team_id', $this->team->id);
    }

    private function decode($response): array
    {
        return json_decode((string) $response->content(), true);
    }

    public function test_feature_list_returns_flags_and_runtime_state(): void
    {
        $data = $this->decode((new FeatureListTool)->handle(new Request([])));

        $this->assertTrue($data['runtime_enabled']);
        $this->assertNotEmpty($data['flags']);
        $this->assertContains('beta_feature', array_column($data['flags'], 'key'));
    }

    public function test_feature_status_unknown_key_is_invalid_argument(): void
    {
        $response = (new FeatureStatusTool)->handle(new Request(['key' => 'nope']));

        $this->assertStringContainsString('Unknown feature flag', (string) $response->content());
    }

    public function test_feature_toggle_non_sensitive_applies(): void
    {
        $data = $this->decode((new FeatureToggleTool)->handle(new Request([
            'key' => 'beta_feature',
            'value' => true,
        ])));

        $this->assertSame('applied', $data['status']);
        $this->assertTrue(app(FeatureFlagService::class)->active('beta_feature', $this->team));
    }

    public function test_feature_toggle_sensitive_returns_pending_approval(): void
    {
        config(['feature_flags.definitions.sensitive_demo' => [
            'label' => 'Sensitive Demo',
            'sensitive' => true,
            'default' => false,
        ]]);
        app(FeatureFlagService::class)->defineAll();

        $data = $this->decode((new FeatureToggleTool)->handle(new Request([
            'key' => 'sensitive_demo',
            'value' => true,
        ])));

        $this->assertSame('pending_approval', $data['status']);
        $this->assertArrayHasKey('approval_id', $data);
        $this->assertFalse(app(FeatureFlagService::class)->active('sensitive_demo', $this->team));
    }

    public function test_feature_rollout_sets_percentage(): void
    {
        $this->decode((new FeatureRolloutTool)->handle(new Request([
            'key' => 'beta_feature',
            'percentage' => 40,
        ])));

        $status = $this->decode((new FeatureStatusTool)->handle(new Request(['key' => 'beta_feature'])));
        $this->assertSame(40, $status['rollout_percentage']);
    }

    public function test_no_team_context_is_permission_denied(): void
    {
        app()->forgetInstance('mcp.team_id');

        $response = (new FeatureListTool)->handle(new Request([]));

        $this->assertStringContainsString('team', strtolower((string) $response->content()));
    }
}
