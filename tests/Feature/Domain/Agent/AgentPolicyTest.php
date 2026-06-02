<?php

namespace Tests\Feature\Domain\Agent;

use App\Domain\Agent\Actions\CreateAgentPolicyAction;
use App\Domain\Agent\Actions\RollbackAgentPolicyAction;
use App\Domain\Agent\Actions\UpdateAgentPolicyAction;
use App\Domain\Agent\Enums\AgentPolicyStatus;
use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentPolicy;
use App\Domain\Agent\Models\AgentPolicyVersion;
use App\Domain\Approval\Actions\CreateActionProposalAction;
use App\Domain\Approval\DTOs\PolicyVerdict;
use App\Domain\Approval\DTOs\ProposalContext;
use App\Domain\Approval\DTOs\ResolvedPolicy;
use App\Domain\Approval\Enums\ActionProposalStatus;
use App\Domain\Approval\Events\ActionProposalApproved;
use App\Domain\Approval\Models\ActionProposal;
use App\Domain\Approval\Services\AgentPolicyResolver;
use App\Domain\Approval\Services\GatePolicyOverlay;
use App\Domain\Approval\Services\PolicyEvaluator;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AgentPolicyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

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
    }

    // --- B: versioning & rollback ------------------------------------------

    public function test_create_seeds_first_version_and_points_to_it(): void
    {
        $policy = app(CreateAgentPolicyAction::class)->execute(
            teamId: $this->team->id,
            name: 'Default',
            rules: ['risk_ceiling' => 'low'],
            createdBy: $this->user->id,
        );

        $this->assertNotNull($policy->current_version_id);
        $version = $policy->currentVersion;
        $this->assertSame(1, $version->version);
        $this->assertSame('low', $version->rules['risk_ceiling']);
        // default_rules merged in:
        $this->assertSame(['migration'], $version->rules['denied_target_types']);
    }

    public function test_update_rules_mints_new_version_and_leaves_old_immutable(): void
    {
        $update = app(UpdateAgentPolicyAction::class);
        $policy = app(CreateAgentPolicyAction::class)->execute(
            teamId: $this->team->id, name: 'P', rules: ['risk_ceiling' => 'low'],
        );
        $v1Id = $policy->current_version_id;

        $policy = $update->execute($policy, rules: ['risk_ceiling' => 'high'], notes: 'loosened');

        $this->assertSame(2, $policy->currentVersion->version);
        $this->assertNotSame($v1Id, $policy->current_version_id);
        // v1 unchanged.
        $this->assertSame('low', AgentPolicyVersion::find($v1Id)->rules['risk_ceiling']);
    }

    public function test_update_without_rules_does_not_create_a_version(): void
    {
        $policy = app(CreateAgentPolicyAction::class)->execute(teamId: $this->team->id, name: 'P');
        app(UpdateAgentPolicyAction::class)->execute($policy, enabled: true);

        $this->assertSame(1, AgentPolicyVersion::where('agent_policy_id', $policy->id)->count());
        $this->assertTrue($policy->fresh()->enabled);
    }

    public function test_rollback_clones_target_rules_into_a_new_version(): void
    {
        $update = app(UpdateAgentPolicyAction::class);
        $policy = app(CreateAgentPolicyAction::class)->execute(
            teamId: $this->team->id, name: 'P', rules: ['risk_ceiling' => 'low'],
        );
        $v1Id = $policy->current_version_id;
        $policy = $update->execute($policy, rules: ['risk_ceiling' => 'critical']);

        $policy = app(RollbackAgentPolicyAction::class)->execute($policy, $v1Id, $this->user->id);

        $this->assertSame(3, $policy->currentVersion->version);
        $this->assertSame('low', $policy->currentVersion->rules['risk_ceiling']);
        $this->assertSame($v1Id, $policy->currentVersion->rolled_back_from_version_id);
    }

    public function test_creating_a_second_policy_for_same_scope_archives_the_first(): void
    {
        $create = app(CreateAgentPolicyAction::class);
        $first = $create->execute(teamId: $this->team->id, name: 'First');
        $create->execute(teamId: $this->team->id, name: 'Second');

        $this->assertSame(AgentPolicyStatus::Archived, $first->fresh()->status);
    }

    // --- B: resolver precedence & backward-compat --------------------------

    public function test_resolver_returns_null_when_flag_off(): void
    {
        config(['agent_policies.enabled' => false]);
        AgentPolicy::factory()->enabled()->create(['team_id' => $this->team->id, 'agent_id' => null]);

        $this->assertNull(app(AgentPolicyResolver::class)->resolve($this->team->id, null));
    }

    public function test_resolver_ignores_disabled_policy(): void
    {
        config(['agent_policies.enabled' => true]);
        app(CreateAgentPolicyAction::class)->execute(teamId: $this->team->id, name: 'P', enabled: false);

        $this->assertNull(app(AgentPolicyResolver::class)->resolve($this->team->id, null));
    }

    public function test_resolver_prefers_agent_specific_over_team_default(): void
    {
        config(['agent_policies.enabled' => true]);
        $agent = Agent::factory()->create(['team_id' => $this->team->id]);
        $create = app(CreateAgentPolicyAction::class);
        $create->execute(teamId: $this->team->id, name: 'team-default', enabled: true);
        $agentPolicy = $create->execute(
            teamId: $this->team->id, name: 'agent-specific', agentId: $agent->id, enabled: true,
        );

        $resolved = app(AgentPolicyResolver::class)->resolve($this->team->id, $agent->id);

        $this->assertNotNull($resolved);
        $this->assertSame($agentPolicy->id, $resolved->policy->id);
    }

    // --- A/B: evaluator verdicts -------------------------------------------

    public function test_evaluator_denies_target_on_deny_list(): void
    {
        $v = $this->evaluate(['denied_target_types' => ['migration']], new ProposalContext('migration', 'low'));
        $this->assertSame(PolicyVerdict::DENY, $v->decision);
    }

    public function test_evaluator_requires_human_outside_allow_list(): void
    {
        $v = $this->evaluate(['allowed_target_types' => ['tool_call']], new ProposalContext('git_push', 'low'));
        $this->assertSame(PolicyVerdict::REQUIRE_HUMAN, $v->decision);
    }

    public function test_evaluator_holds_sensitive_path_and_raises_risk(): void
    {
        $v = $this->evaluate(
            ['sensitive_paths' => ['*/Billing/*'], 'risk_ceiling' => 'high'],
            new ProposalContext('git_push', 'low', paths: ['app/Domain/Billing/Charge.php']),
        );
        $this->assertSame(PolicyVerdict::REQUIRE_HUMAN, $v->decision);
        $this->assertSame('high', $v->effectiveRisk);
    }

    public function test_evaluator_holds_above_risk_ceiling(): void
    {
        $v = $this->evaluate(['risk_ceiling' => 'medium'], new ProposalContext('git_push', 'high'));
        $this->assertSame(PolicyVerdict::REQUIRE_HUMAN, $v->decision);
    }

    public function test_evaluator_always_holds_critical_even_when_opted_in(): void
    {
        $v = $this->evaluate(
            ['risk_ceiling' => 'critical', 'auto_execute' => ['enabled' => true, 'threshold' => 0]],
            new ProposalContext('tool_call', 'critical'),
        );
        $this->assertSame(PolicyVerdict::REQUIRE_HUMAN, $v->decision);
    }

    public function test_evaluator_allows_auto_when_opted_in_and_within_ceiling(): void
    {
        $v = $this->evaluate(
            ['risk_ceiling' => 'medium', 'auto_execute' => ['enabled' => true, 'threshold' => 18]],
            new ProposalContext('tool_call', 'medium', rubricTotal: 20),
        );
        $this->assertSame(PolicyVerdict::ALLOW_AUTO, $v->decision);
    }

    public function test_evaluator_holds_when_auto_execute_not_opted_in(): void
    {
        $v = $this->evaluate(['risk_ceiling' => 'high'], new ProposalContext('tool_call', 'low'));
        $this->assertSame(PolicyVerdict::REQUIRE_HUMAN, $v->decision);
    }

    public function test_evaluator_holds_when_frequency_cap_reached(): void
    {
        ActionProposal::create([
            'team_id' => $this->team->id,
            'actor_agent_id' => null,
            'target_type' => 'tool_call',
            'summary' => 'x',
            'payload' => [],
            'risk_level' => 'low',
            'status' => ActionProposalStatus::Pending->value,
        ]);

        $v = $this->evaluate(
            ['risk_ceiling' => 'high', 'frequency_cap' => ['count' => 1, 'window' => 'day'],
                'auto_execute' => ['enabled' => true, 'threshold' => 0]],
            new ProposalContext('tool_call', 'low'),
        );

        $this->assertSame(PolicyVerdict::REQUIRE_HUMAN, $v->decision);
        $this->assertStringContainsString('Frequency cap', $v->reason);
    }

    // --- A/C: integration with CreateActionProposalAction ------------------

    public function test_flag_off_leaves_proposal_unchanged_and_unpinned(): void
    {
        config(['agent_policies.enabled' => false]);
        app(CreateAgentPolicyAction::class)->execute(teamId: $this->team->id, name: 'P', enabled: true);

        $proposal = app(CreateActionProposalAction::class)->execute(
            teamId: $this->team->id, targetType: 'tool_call', targetId: null,
            summary: 'x', payload: [], riskLevel: 'high',
        );

        $this->assertSame(ActionProposalStatus::Pending, $proposal->status);
        $this->assertNull($proposal->agent_policy_version_id);
    }

    public function test_policy_deny_rejects_proposal_and_pins_version(): void
    {
        config(['agent_policies.enabled' => true]);
        $policy = app(CreateAgentPolicyAction::class)->execute(
            teamId: $this->team->id, name: 'P', enabled: true,
            rules: ['denied_target_types' => ['git_push']],
        );

        $proposal = app(CreateActionProposalAction::class)->execute(
            teamId: $this->team->id, targetType: 'git_push', targetId: null,
            summary: 'x', payload: [], riskLevel: 'medium',
        );

        $this->assertSame(ActionProposalStatus::Rejected, $proposal->status);
        $this->assertSame($policy->current_version_id, $proposal->agent_policy_version_id);
        $this->assertSame(PolicyVerdict::DENY, $proposal->rubric_breakdown['policy_decision']['decision']);
    }

    public function test_policy_require_human_keeps_pending_and_records_decision(): void
    {
        config(['agent_policies.enabled' => true]);
        app(CreateAgentPolicyAction::class)->execute(
            teamId: $this->team->id, name: 'P', enabled: true,
            rules: ['risk_ceiling' => 'low'], // high-risk action exceeds ceiling
        );

        $proposal = app(CreateActionProposalAction::class)->execute(
            teamId: $this->team->id, targetType: 'tool_call', targetId: null,
            summary: 'x', payload: [], riskLevel: 'high',
        );

        $this->assertSame(ActionProposalStatus::Pending, $proposal->status);
        $this->assertSame(PolicyVerdict::REQUIRE_HUMAN, $proposal->rubric_breakdown['policy_decision']['decision']);
    }

    public function test_policy_allow_auto_approves_and_fires_event(): void
    {
        config(['agent_policies.enabled' => true]);
        Event::fake([ActionProposalApproved::class]);
        app(CreateAgentPolicyAction::class)->execute(
            teamId: $this->team->id, name: 'P', enabled: true,
            rules: ['risk_ceiling' => 'high', 'auto_execute' => ['enabled' => true, 'threshold' => 0]],
        );

        $proposal = app(CreateActionProposalAction::class)->execute(
            teamId: $this->team->id, targetType: 'tool_call', targetId: null,
            summary: 'x', payload: [], riskLevel: 'low',
        );

        $this->assertSame(ActionProposalStatus::Approved, $proposal->status);
        Event::assertDispatched(ActionProposalApproved::class);
    }

    // --- A: gate overlay (escalate-only) -----------------------------------

    public function test_gate_overlay_returns_blob_unchanged_when_flag_off(): void
    {
        config(['agent_policies.enabled' => false]);
        app(CreateAgentPolicyAction::class)->execute(
            teamId: $this->team->id, name: 'P', enabled: true, rules: ['risk_ceiling' => 'low'],
        );

        $out = app(GatePolicyOverlay::class)
            ->decide($this->team->id, 'git_push', 'high', [], 'auto');

        $this->assertSame('auto', $out);
    }

    public function test_gate_overlay_escalates_auto_to_ask_above_ceiling(): void
    {
        config(['agent_policies.enabled' => true]);
        app(CreateAgentPolicyAction::class)->execute(
            teamId: $this->team->id, name: 'P', enabled: true, rules: ['risk_ceiling' => 'low'],
        );

        $out = app(GatePolicyOverlay::class)
            ->decide($this->team->id, 'git_push', 'high', [], 'auto');

        $this->assertSame('ask', $out);
    }

    public function test_gate_overlay_never_downgrades_a_stricter_blob(): void
    {
        config(['agent_policies.enabled' => true]);
        app(CreateAgentPolicyAction::class)->execute(
            teamId: $this->team->id, name: 'P', enabled: true,
            rules: ['risk_ceiling' => 'high', 'auto_execute' => ['enabled' => true, 'threshold' => 0]],
        );

        // policy would allow_auto, but the blob already says reject → stays reject.
        $out = app(GatePolicyOverlay::class)
            ->decide($this->team->id, 'git_push', 'low', [], 'reject');

        $this->assertSame('reject', $out);
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    private function evaluate(array $rules, ProposalContext $ctx): PolicyVerdict
    {
        $policy = AgentPolicy::factory()->create(['team_id' => $this->team->id]);
        $version = AgentPolicyVersion::factory()->create([
            'team_id' => $this->team->id,
            'agent_policy_id' => $policy->id,
            'rules' => array_merge(config('agent_policies.default_rules', []), $rules),
        ]);

        return app(PolicyEvaluator::class)->evaluate(new ResolvedPolicy($policy, $version), $ctx);
    }
}
