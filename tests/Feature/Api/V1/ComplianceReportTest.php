<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use App\Domain\Approval\Models\ActionProposal;
use App\Domain\Audit\Listeners\LogSafetyViolation;
use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Audit\Services\EuAiActReportBuilder;
use App\Domain\Shared\Models\Team;
use App\Domain\Tool\Enums\ToolRiskLevel;
use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Models\Tool;
use App\Domain\Tool\Services\ToolOutputGuard;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Events\SafetyViolationDetected;
use App\Livewire\Compliance\EuAiActReportPage;
use App\Mcp\Tools\System\ComplianceEuAiActReportTool;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

/**
 * EU AI Act evidence report: builder, safety-violation persistence and the three
 * surfaces (page, API, MCP).
 */
class ComplianceReportTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['audit.compliance_report.enabled' => true]);
    }

    private function seedTeamData(Team $team): void
    {
        $agent = Agent::create([
            'id' => (string) Str::uuid7(),
            'team_id' => $team->id,
            'name' => 'Report Agent',
            'slug' => 'report-agent-'.Str::lower(Str::random(4)),
            'role' => 'assistant',
            'goal' => 'help',
            'backstory' => 'test',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet',
            'status' => AgentStatus::Active,
            'config' => [],
        ]);

        $tool = Tool::factory()->create([
            'team_id' => $team->id,
            'type' => ToolType::McpHttp,
            'status' => ToolStatus::Active,
            'risk_level' => ToolRiskLevel::Write,
        ]);
        $agent->tools()->attach($tool->id, ['priority' => 0, 'approval_mode' => 'ask']);

        AuditEntry::create(['team_id' => $team->id, 'event' => 'agent.created', 'properties' => [], 'created_at' => now()]);
        AuditEntry::create([
            'team_id' => $team->id,
            'event' => ToolOutputGuard::AUDIT_EVENT,
            'properties' => ['scanner' => 'prompt_injection'],
            'created_at' => now(),
        ]);

        ActionProposal::create([
            'team_id' => $team->id,
            'target_type' => 'agent_tool_call',
            'summary' => 'call',
            'payload' => [],
            'risk_level' => 'high',
            'status' => 'approved',
        ]);
    }

    private function section(array $report, string $key): array
    {
        return collect($report['sections'])->firstWhere('key', $key);
    }

    public function test_builder_reports_all_sections_for_own_team_only(): void
    {
        $this->seedTeamData($this->team);

        $other = Team::create(['name' => 'Other', 'slug' => 'other-team', 'owner_id' => User::factory()->create()->id, 'settings' => []]);
        $this->seedTeamData($other);

        [$from, $to] = EuAiActReportBuilder::resolvePeriod(null, null);
        $report = app(EuAiActReportBuilder::class)->build($this->team->id, $from, $to);

        $this->assertSame(['risk_management', 'record_keeping', 'transparency', 'human_oversight', 'robustness'], array_column($report['sections'], 'key'));

        $risk = $this->section($report, 'risk_management')['evidence'];
        $this->assertSame(1, $risk['agents']);
        $this->assertSame(['write' => 1], $risk['tools_by_risk_level']);
        $this->assertSame(1, $risk['tool_attachments_requiring_approval']);

        $this->assertSame(2, $this->section($report, 'record_keeping')['evidence']['audit_entries_in_period']);
        $this->assertSame(['approved' => 1], $this->section($report, 'human_oversight')['evidence']['action_proposals_by_status']);
        $this->assertSame(['prompt_injection' => 1], $this->section($report, 'robustness')['evidence']['tool_output_threats_by_scanner']);
        $this->assertSame(EuAiActReportBuilder::DISCLAIMER, $report['disclaimer']);
    }

    public function test_gaps_are_reported_when_controls_are_off(): void
    {
        config(['tools.definition_pinning.enabled' => false, 'ai_safety.tool_output_scan.enabled' => false]);

        [$from, $to] = EuAiActReportBuilder::resolvePeriod(null, null);
        $report = app(EuAiActReportBuilder::class)->build($this->team->id, $from, $to);

        $this->assertSame('gap', $this->section($report, 'robustness')['status']);
        $this->assertSame('gap', $this->section($report, 'risk_management')['status']);
    }

    public function test_period_validation(): void
    {
        $this->assertSame('2026-01-01', EuAiActReportBuilder::resolvePeriod('2026-01-01', '2026-02-01')[0]->toDateString());

        foreach ([['2026-02-01', '2026-01-01'], ['2020-01-01', '2026-01-01'], ['01/02/2026', null], ['2026-02-30', null]] as [$from, $to]) {
            try {
                EuAiActReportBuilder::resolvePeriod($from, $to);
                $this->fail("Expected rejection for {$from}..{$to}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_safety_violation_event_is_persisted_once(): void
    {
        $request = new AiRequestDTO(provider: 'anthropic', model: 'claude-sonnet', systemPrompt: '', userPrompt: 'x', teamId: $this->team->id);

        event(new SafetyViolationDetected($request, ['rule_id' => 'jailbreak-dan', 'severity' => 'medium', 'target' => 'input', 'snippet' => 'DAN mode'], 'advisory', 1));

        $entries = AuditEntry::withoutGlobalScopes()->where('event', LogSafetyViolation::EVENT)->get();
        $this->assertCount(1, $entries);
        $this->assertSame('jailbreak-dan', $entries[0]->properties['rule_id']);
        $this->assertArrayNotHasKey('snippet', $entries[0]->properties);
        $this->assertSame($this->team->id, $entries[0]->team_id);
    }

    public function test_api_returns_report(): void
    {
        $this->actingAsApiUser();

        $this->getJson('/api/v1/compliance/eu-ai-act?from=2026-01-01&to=2026-03-31')
            ->assertOk()
            ->assertJsonPath('data.period.from', fn ($v) => str_starts_with($v, '2026-01-01'))
            ->assertJsonCount(5, 'data.sections');
    }

    public function test_api_rejects_bad_period_and_hides_when_disabled(): void
    {
        $this->actingAsApiUser();

        $this->getJson('/api/v1/compliance/eu-ai-act?from=2026-03-01&to=2026-01-01')->assertStatus(422);

        config(['audit.compliance_report.enabled' => false]);
        $this->getJson('/api/v1/compliance/eu-ai-act')->assertNotFound();
    }

    public function test_page_renders_and_is_hidden_when_disabled(): void
    {
        $this->actingAs($this->user);

        Livewire::test(EuAiActReportPage::class)
            ->assertOk()
            ->assertSee('Art. 14')
            ->assertSee('Human oversight')
            ->set('from', '2026-03-01')
            ->set('to', '2026-01-01')
            ->assertSee('The start date must be on or before the end date.');

        config(['audit.compliance_report.enabled' => false]);
        $this->get('/compliance/eu-ai-act')->assertNotFound();
    }

    public function test_mcp_tool_returns_report_for_bound_team(): void
    {
        $this->actingAs($this->user);
        app()->instance('mcp.team_id', $this->team->id);

        $response = (new ComplianceEuAiActReportTool)->handle(new Request(['from' => '2026-01-01', 'to' => '2026-01-31']));
        $data = json_decode((string) $response->content(), true);

        $this->assertSame($this->team->id, $data['team_id']);
        $this->assertCount(5, $data['sections']);
    }

    public function test_mcp_tool_and_api_deny_without_manage_team(): void
    {
        // Community edition allows everyone; cloud maps manage-team to owner/admin.
        Gate::define('manage-team', fn () => false);
        $viewer = $this->createTeamMember('viewer');
        $this->actingAs($viewer);
        app()->instance('mcp.team_id', $this->team->id);

        $response = (new ComplianceEuAiActReportTool)->handle(new Request([]));
        $this->assertTrue($response->isError());

        Sanctum::actingAs($viewer, ['*']);
        $this->getJson('/api/v1/compliance/eu-ai-act')->assertForbidden();
    }

    public function test_classifier_counts_as_on_only_when_the_platform_switch_is_on(): void
    {
        $this->team->update(['settings' => ['safety_classifier_enabled' => true]]);
        [$from, $to] = EuAiActReportBuilder::resolvePeriod(null, null);

        config(['ai_safety.enabled' => false]);
        $off = $this->section(app(EuAiActReportBuilder::class)->build($this->team->id, $from, $to), 'robustness');
        $this->assertFalse($off['evidence']['gateway_safety_classifier_enabled']);

        config(['ai_safety.enabled' => true]);
        $on = $this->section(app(EuAiActReportBuilder::class)->build($this->team->id, $from, $to), 'robustness');
        $this->assertTrue($on['evidence']['gateway_safety_classifier_enabled']);
    }

    public function test_mcp_tool_checks_role_in_the_bound_team_not_the_current_one(): void
    {
        $other = Team::create(['name' => 'Bound', 'slug' => 'bound-team', 'owner_id' => User::factory()->create()->id, 'settings' => []]);
        $other->users()->attach($this->user, ['role' => 'viewer']);

        $this->actingAs($this->user);
        app()->instance('mcp.team_id', $other->id);

        $this->assertTrue((new ComplianceEuAiActReportTool)->handle(new Request([]))->isError());
    }
}
