<?php

namespace Tests\Feature\Domain\Approval;

use App\Domain\Agent\Models\Agent;
use App\Domain\Approval\Actions\ApproveActionProposalAction;
use App\Domain\Approval\Actions\CreatePermissionDenialProposalsAction;
use App\Domain\Approval\Enums\ActionProposalStatus;
use App\Domain\Approval\Models\ActionProposal;
use App\Domain\Approval\Services\ActionProposalExecutor;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Gateways\ClaudeCodeSettingsBuilder;
use App\Infrastructure\AI\Services\ClaudeCodeMcpConfigBuilder;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class ClaudeCodePermissionProposalTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        config(['decision_rubric.enabled' => false]);

        $this->owner = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Grant Team',
            'slug' => 'grant-'.Str::lower(Str::random(6)),
            'owner_id' => $this->owner->id,
            'settings' => [],
        ]);
        $this->owner->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->owner, ['role' => 'owner']);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function makeAgent(array $config = []): Agent
    {
        return Agent::factory()->create(['team_id' => $this->team->id, 'name' => 'Builder', 'config' => $config]);
    }

    /**
     * @param  array<int, array{tool_name: string, tool_input: array<mixed>}>  $denials
     */
    private function propose(Agent $agent, array $denials): int
    {
        return app(CreatePermissionDenialProposalsAction::class)->execute($this->team->id, $agent->id, $denials, null);
    }

    /**
     * @return Collection<int, ActionProposal>
     */
    private function proposalsFor(Agent $agent): Collection
    {
        return ActionProposal::withoutGlobalScopes()
            ->where('target_type', CreatePermissionDenialProposalsAction::TARGET_TYPE)
            ->where('target_id', $agent->id)
            ->orderBy('created_at')
            ->get();
    }

    /**
     * @return list<mixed>
     */
    private function proposedRules(Agent $agent): array
    {
        return $this->proposalsFor($agent)->map(fn (ActionProposal $p) => $p->payload['rule'] ?? null)->sort()->values()->all();
    }

    public function test_maps_denials_to_rules_and_creates_one_proposal_per_rule(): void
    {
        $agent = $this->makeAgent();

        $created = $this->propose($agent, [
            ['tool_name' => 'Bash', 'tool_input' => ['command' => 'git push origin x']],
            ['tool_name' => 'Bash', 'tool_input' => ['command' => 'git status']],
            ['tool_name' => 'WebFetch', 'tool_input' => ['url' => 'https://example.test/?token=secret-value']],
            ['tool_name' => 'Edit', 'tool_input' => ['file_path' => '/srv/app/notes.txt', 'old_string' => 'a', 'new_string' => 'b']],
        ]);

        $this->assertSame(3, $created);
        $this->assertSame(['Bash(git:*)', 'Edit(//srv/app/notes.txt)', 'WebFetch'], $this->proposedRules($agent));

        $proposal = $this->proposalsFor($agent)->first();
        $this->assertSame(ActionProposalStatus::Pending, $proposal->status);
        $this->assertSame('high', $proposal->risk_level);
        $this->assertSame($agent->id, $proposal->actor_agent_id);
        $this->assertStringContainsString('Builder', $proposal->summary);

        foreach ($this->proposalsFor($agent) as $p) {
            $this->assertSame(['agent_id', 'rule', 'tool_name', 'experiment_id'], array_keys($p->payload));
            $encoded = (string) json_encode($p->payload);
            $this->assertStringNotContainsString('origin', $encoded);
            $this->assertStringNotContainsString('secret-value', $encoded);
        }
    }

    public function test_skips_sudo_mcp_unsafe_paths_and_unknown_programs(): void
    {
        $agent = $this->makeAgent();

        $created = $this->propose($agent, [
            ['tool_name' => 'Bash', 'tool_input' => ['command' => 'sudo apt-get install x']],
            ['tool_name' => 'Bash', 'tool_input' => ['command' => 'cd /tmp && sudo rm -rf x']],
            ['tool_name' => 'Bash', 'tool_input' => ['command' => '"unterminated']],
            ['tool_name' => 'mcp__github__create_issue', 'tool_input' => []],
            ['tool_name' => 'Edit', 'tool_input' => ['file_path' => 'relative/file.txt']],
            ['tool_name' => 'Write', 'tool_input' => ['file_path' => '/srv/app/../etc/passwd']],
            ['tool_name' => 'Weird-Name', 'tool_input' => []],
        ]);

        $this->assertSame(0, $created);
        $this->assertCount(0, $this->proposalsFor($agent));
    }

    public function test_skips_bash_rules_when_shell_is_off(): void
    {
        $agent = $this->makeAgent(['allow_shell' => false]);

        $created = $this->propose($agent, [
            ['tool_name' => 'Bash', 'tool_input' => ['command' => 'git status']],
            ['tool_name' => 'WebSearch', 'tool_input' => ['query' => 'x']],
        ]);

        $this->assertSame(1, $created);
        $this->assertSame(['WebSearch'], $this->proposedRules($agent));
    }

    public function test_skips_pending_and_already_granted_rules(): void
    {
        $agent = $this->makeAgent([ClaudeCodeSettingsBuilder::GRANTS_CONFIG_KEY => ['allow' => ['WebSearch']]]);

        $this->assertSame(1, $this->propose($agent, [['tool_name' => 'WebFetch', 'tool_input' => []]]));
        $this->assertSame(0, $this->propose($agent, [
            ['tool_name' => 'WebFetch', 'tool_input' => []],
            ['tool_name' => 'WebSearch', 'tool_input' => []],
        ]));
        $this->assertCount(1, $this->proposalsFor($agent));
    }

    public function test_agent_of_another_team_gets_no_proposal(): void
    {
        $other = Agent::factory()->create(['team_id' => Team::factory()->create()->id]);

        $this->assertSame(0, $this->propose($other, [['tool_name' => 'WebFetch', 'tool_input' => []]]));
        $this->assertCount(0, $this->proposalsFor($other));
    }

    public function test_approving_stores_a_lasting_grant_used_by_the_settings(): void
    {
        $agent = $this->makeAgent();
        $this->propose($agent, [['tool_name' => 'Bash', 'tool_input' => ['command' => 'git push origin x']]]);
        $proposal = $this->proposalsFor($agent)->firstOrFail();

        $approved = app(ApproveActionProposalAction::class)->execute($proposal, $this->owner);
        $result = app(ActionProposalExecutor::class)->execute($approved, $this->owner);
        // Approving twice does not duplicate the grant.
        app(ActionProposalExecutor::class)->execute($approved, $this->owner);

        $this->assertSame(['agent_id' => $agent->id, 'rule' => 'Bash(git:*)', 'granted' => true], $result);
        $agent->refresh();
        $this->assertSame(['Bash(git:*)'], $agent->config[ClaudeCodeSettingsBuilder::GRANTS_CONFIG_KEY]['allow']);

        $settings = (new ClaudeCodeSettingsBuilder(new ClaudeCodeMcpConfigBuilder))->forAgent($agent, null, null);
        $this->assertContains('Bash(git:*)', $settings['permissions']['allow']);
    }

    public function test_only_owner_or_admin_can_grant(): void
    {
        $agent = $this->makeAgent();
        $this->propose($agent, [['tool_name' => 'WebFetch', 'tool_input' => []]]);
        $proposal = $this->proposalsFor($agent)->firstOrFail();

        $member = User::factory()->create(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($member, ['role' => 'member']);

        try {
            app(ApproveActionProposalAction::class)->execute($proposal, $member);
            $this->fail('A member must not grant a Claude Code permission.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('owner or admin', $e->getMessage());
            $this->assertSame(ActionProposalStatus::Pending, $proposal->refresh()->status);
        }

        try {
            app(ActionProposalExecutor::class)->execute($proposal, $member);
            $this->fail('The executor must refuse a member.');
        } catch (RuntimeException) {
            $agent->refresh();
            $this->assertArrayNotHasKey(ClaudeCodeSettingsBuilder::GRANTS_CONFIG_KEY, $agent->config ?? []);
        }
    }

    public function test_executor_refuses_ungrantable_rules(): void
    {
        $agent = $this->makeAgent(['allow_shell' => false]);

        foreach (['Bash(sudo:*)', "WebFetch\nBash", 'Bash(git:*)'] as $rule) {
            $proposal = ActionProposal::create([
                'team_id' => $this->team->id,
                'actor_agent_id' => $agent->id,
                'target_type' => CreatePermissionDenialProposalsAction::TARGET_TYPE,
                'target_id' => $agent->id,
                'summary' => 'grant',
                'payload' => ['agent_id' => $agent->id, 'rule' => $rule, 'tool_name' => 'Bash', 'experiment_id' => null],
                'lineage' => [],
                'risk_level' => 'high',
                'status' => 'approved',
            ]);

            try {
                app(ActionProposalExecutor::class)->execute($proposal, $this->owner);
                $this->fail("Rule {$rule} must not be granted.");
            } catch (RuntimeException) {
                $agent->refresh();
                $this->assertArrayNotHasKey(ClaudeCodeSettingsBuilder::GRANTS_CONFIG_KEY, $agent->config ?? []);
            }
        }
    }
}
