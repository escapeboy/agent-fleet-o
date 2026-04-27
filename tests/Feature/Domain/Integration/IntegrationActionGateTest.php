<?php

namespace Tests\Feature\Domain\Integration;

use App\Domain\Approval\Enums\ActionProposalStatus;
use App\Domain\Approval\Models\ActionProposal;
use App\Domain\Integration\Exceptions\IntegrationActionProposedException;
use App\Domain\Integration\Exceptions\IntegrationActionRefusedException;
use App\Domain\Integration\Models\Integration;
use App\Domain\Integration\Services\IntegrationActionGate;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntegrationActionGateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'T '.bin2hex(random_bytes(3)),
            'slug' => 't-'.bin2hex(random_bytes(3)),
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);

        $this->actingAs($this->user);

        $this->integration = Integration::factory()->create([
            'team_id' => $this->team->id,
            'driver' => 'github',
            'name' => 'Test GitHub',
        ]);
    }

    public function test_default_policy_passes_through(): void
    {
        $gate = app(IntegrationActionGate::class);
        $gate->check($this->integration, 'create_issue', ['title' => 'X']);

        $this->assertSame(0, ActionProposal::count());
    }

    public function test_policy_ask_at_high_creates_proposal_and_throws(): void
    {
        $this->team->update(['settings' => ['action_proposal_policy' => [
            'low' => 'auto',
            'medium' => 'auto',
            'high' => 'ask',
        ]]]);

        $gate = app(IntegrationActionGate::class);

        try {
            $gate->check($this->integration, 'delete_branch', ['branch' => 'feature/x']);
            $this->fail('Expected IntegrationActionProposedException');
        } catch (IntegrationActionProposedException $e) {
            $this->assertSame('high', $e->riskLevel);
            $this->assertSame('delete_branch', $e->action);

            $proposal = ActionProposal::find($e->proposalId);
            $this->assertNotNull($proposal);
            $this->assertSame('integration_action', $proposal->target_type);
            $this->assertSame($this->integration->id, $proposal->target_id);
            $this->assertSame('delete_branch', $proposal->payload['action']);
            $this->assertSame(['branch' => 'feature/x'], $proposal->payload['params']);
            $this->assertSame(ActionProposalStatus::Pending, $proposal->status);
        }
    }

    public function test_policy_reject_throws_without_proposal(): void
    {
        $this->team->update(['settings' => ['action_proposal_policy' => [
            'low' => 'auto',
            'medium' => 'auto',
            'high' => 'reject',
        ]]]);

        $gate = app(IntegrationActionGate::class);

        try {
            $gate->check($this->integration, 'delete_repo', ['repo' => 'foo']);
            $this->fail('Expected IntegrationActionRefusedException');
        } catch (IntegrationActionRefusedException $e) {
            $this->assertSame('high', $e->riskLevel);
            $this->assertStringContainsString('refused by team policy', $e->getMessage());
        }

        $this->assertSame(0, ActionProposal::count(), 'reject must NOT create a proposal');
    }

    public function test_legacy_slow_mode_enabled_maps_to_high_ask(): void
    {
        $this->team->update(['settings' => ['slow_mode_enabled' => true]]);

        $gate = app(IntegrationActionGate::class);

        // medium should pass (legacy slow_mode only gates high)
        $gate->check($this->integration, 'create_issue', ['title' => 'X']);
        $this->assertSame(0, ActionProposal::count());

        // high should be gated
        $this->expectException(IntegrationActionProposedException::class);
        $gate->check($this->integration, 'delete_branch', ['branch' => 'main']);
    }

    public function test_bypass_binding_short_circuits_gate(): void
    {
        $this->team->update(['settings' => ['action_proposal_policy' => ['low' => 'auto', 'medium' => 'auto', 'high' => 'reject']]]);

        app()->instance('integration_gate.bypass', true);
        try {
            // High-risk action that would normally be REJECTED — bypass lets it through.
            app(IntegrationActionGate::class)->check($this->integration, 'delete_branch', []);
        } finally {
            app()->forgetInstance('integration_gate.bypass');
        }

        $this->assertSame(0, ActionProposal::count());
    }

    /**
     * @dataProvider riskClassificationCases
     */
    public function test_classify_action(string $action, string $expected): void
    {
        $this->assertSame($expected, IntegrationActionGate::classifyAction($action));
    }

    public static function riskClassificationCases(): array
    {
        return [
            // High
            ['delete_branch', 'high'],
            ['remove_member', 'high'],
            ['drop_table', 'high'],
            ['terminate_session', 'high'],
            ['force_push', 'high'],
            ['rollback_deploy', 'high'],
            ['archive_project', 'high'],
            // Medium
            ['create_issue', 'medium'],
            ['update_user', 'medium'],
            ['send_message', 'medium'],
            ['post_tweet', 'medium'],
            ['commit_files', 'medium'],
            ['push_branch', 'medium'],
            ['merge_pr', 'medium'],
            ['deploy_app', 'medium'],
            ['invite_user', 'medium'],
            ['transfer_ownership', 'medium'],
            // Low (read / unknown)
            ['list_repos', 'low'],
            ['get_issue', 'low'],
            ['fetch_metrics', 'low'],
            ['search_users', 'low'],
            ['ping', 'low'],
            ['validate_token', 'low'],
            ['something_unknown', 'low'],
        ];
    }
}
