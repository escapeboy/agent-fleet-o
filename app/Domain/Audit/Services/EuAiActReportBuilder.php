<?php

namespace App\Domain\Audit\Services;

use App\Domain\Audit\Listeners\LogSafetyViolation;
use App\Domain\Tool\Services\ToolOutputGuard;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Builds a supporting-evidence report for the EU AI Act from data FleetQ already
 * records: audit trail, approvals, safety events, LLM usage and tool governance.
 *
 * Every query is filtered by team_id explicitly (no reliance on TeamScope), so
 * the builder is safe from console, API and MCP contexts alike.
 *
 * The output maps evidence to articles; it is not a conformity assessment.
 */
class EuAiActReportBuilder
{
    public const DISCLAIMER = 'This report collects evidence recorded by FleetQ for the selected period and maps it to EU AI Act articles. It is supporting material for your own assessment, not a conformity assessment or legal advice. The obligations that apply depend on your role (provider or deployer) and on the risk class of your AI system.';

    /**
     * Resolve a reporting period from optional Y-m-d strings. Defaults to the
     * last 90 days.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     *
     * @throws InvalidArgumentException on an unparsable date, from > to, or a range above audit.compliance_report.max_range_days
     */
    public static function resolvePeriod(?string $from, ?string $to): array
    {
        $parse = static function (string $date): CarbonImmutable {
            try {
                $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date);
            } catch (Throwable) {
                $parsed = null;
            }

            return $parsed instanceof CarbonImmutable && $parsed->format('Y-m-d') === $date
                ? $parsed
                : throw new InvalidArgumentException('Dates must use the format YYYY-MM-DD.');
        };

        $end = $to ? $parse($to)->endOfDay() : CarbonImmutable::now()->endOfDay();
        $start = $from ? $parse($from)->startOfDay() : $end->subDays(90)->startOfDay();

        if ($start->greaterThan($end)) {
            throw new InvalidArgumentException('The start date must be on or before the end date.');
        }

        $maxDays = (int) config('audit.compliance_report.max_range_days', 366);
        if ($start->diffInDays($end) > $maxDays) {
            throw new InvalidArgumentException("The period may span at most {$maxDays} days.");
        }

        return [$start, $end];
    }

    /**
     * @return array{framework: string, team_id: string, period: array{from: string, to: string}, generated_at: string, disclaimer: string, sections: list<array{key: string, article: string, title: string, status: string, evidence: array<string, mixed>, gaps: list<string>}>}
     */
    public function build(string $teamId, CarbonInterface $from, CarbonInterface $to): array
    {
        return [
            'framework' => 'EU AI Act (Regulation (EU) 2024/1689)',
            'team_id' => $teamId,
            'period' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'generated_at' => now()->toIso8601String(),
            'disclaimer' => self::DISCLAIMER,
            'sections' => [
                $this->riskManagement($teamId),
                $this->recordKeeping($teamId, $from, $to),
                $this->transparency($teamId, $from, $to),
                $this->humanOversight($teamId, $from, $to),
                $this->robustness($teamId, $from, $to),
            ],
        ];
    }

    /**
     * @return array{key: string, article: string, title: string, status: string, evidence: array<string, mixed>, gaps: list<string>}
     */
    private function riskManagement(string $teamId): array
    {
        $toolsByRisk = DB::table('tools')
            ->where('team_id', $teamId)
            ->whereNull('deleted_at')
            ->selectRaw('risk_level, count(*) as total')
            ->groupBy('risk_level')
            ->pluck('total', 'risk_level')
            ->map(fn ($n) => (int) $n)
            ->all();

        $attachments = DB::table('agent_tool')
            ->join('agents', 'agents.id', '=', 'agent_tool.agent_id')
            ->where('agents.team_id', $teamId)
            ->whereNull('agents.deleted_at');

        $totalAttachments = (clone $attachments)->count();
        $askAttachments = (clone $attachments)->where('agent_tool.approval_mode', 'ask')->count();

        $pendingDefinitionChanges = DB::table('tools')
            ->where('team_id', $teamId)
            ->whereNull('deleted_at')
            ->whereNotNull('pending_definitions_hash')
            ->count();

        $gaps = [];
        $highRiskTools = ($toolsByRisk['write'] ?? 0) + ($toolsByRisk['destructive'] ?? 0);
        if ($highRiskTools > 0 && $askAttachments === 0) {
            $gaps[] = 'Write or destructive tools are attached to agents, but no tool attachment requires human approval (approval_mode = ask).';
        }
        if (! config('tools.definition_pinning.enabled')) {
            $gaps[] = 'MCP definition pinning is off: a change in a third-party tool definition is applied without review.';
        }
        if ($pendingDefinitionChanges > 0) {
            $gaps[] = "{$pendingDefinitionChanges} MCP tool definition change(s) await review.";
        }

        return $this->section('risk_management', 'Art. 9', 'Risk management', [
            'agents' => DB::table('agents')->where('team_id', $teamId)->whereNull('deleted_at')->count(),
            'tools_by_risk_level' => $toolsByRisk,
            'tool_attachments' => $totalAttachments,
            'tool_attachments_requiring_approval' => $askAttachments,
            'mcp_definition_pinning_enabled' => (bool) config('tools.definition_pinning.enabled'),
            'mcp_definition_changes_pending' => $pendingDefinitionChanges,
        ], $gaps);
    }

    /**
     * @return array{key: string, article: string, title: string, status: string, evidence: array<string, mixed>, gaps: list<string>}
     */
    private function recordKeeping(string $teamId, CarbonInterface $from, CarbonInterface $to): array
    {
        $inPeriod = DB::table('audit_entries')
            ->where('team_id', $teamId)
            ->whereBetween('created_at', [$from, $to]);

        $total = (clone $inPeriod)->count();
        $chained = (clone $inPeriod)->whereNotNull('entry_hash')->count();
        $oldest = DB::table('audit_entries')->where('team_id', $teamId)->min('created_at');

        $gaps = [];
        if ($total === 0) {
            $gaps[] = 'No audit entries were recorded in this period.';
        }
        if (! config('audit.hash_chain.enabled')) {
            $gaps[] = 'Audit hash chaining is off: entries are not tamper-evident.';
        }

        return $this->section('record_keeping', 'Art. 12', 'Record-keeping (automatic logs)', [
            'audit_entries_in_period' => $total,
            'hash_chained_entries_in_period' => $chained,
            'hash_chain_enabled' => (bool) config('audit.hash_chain.enabled'),
            'oldest_audit_entry_at' => $oldest !== null ? (string) $oldest : null,
            'llm_calls_logged_in_period' => DB::table('llm_request_logs')
                ->where('team_id', $teamId)
                ->whereBetween('created_at', [$from, $to])
                ->count(),
        ], $gaps);
    }

    /**
     * @return array{key: string, article: string, title: string, status: string, evidence: array<string, mixed>, gaps: list<string>}
     */
    private function transparency(string $teamId, CarbonInterface $from, CarbonInterface $to): array
    {
        $models = DB::table('llm_request_logs')
            ->where('team_id', $teamId)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('provider, model, count(*) as calls')
            ->groupBy('provider', 'model')
            ->orderByDesc('calls')
            ->get()
            ->map(fn ($row) => ['provider' => $row->provider, 'model' => $row->model, 'calls' => (int) $row->calls])
            ->all();

        return $this->section('transparency', 'Art. 13 / Art. 50', 'Transparency — models in use', [
            'models_used_in_period' => $models,
        ], $models === [] ? ['No LLM calls were logged in this period, so no model inventory could be derived.'] : []);
    }

    /**
     * @return array{key: string, article: string, title: string, status: string, evidence: array<string, mixed>, gaps: list<string>}
     */
    private function humanOversight(string $teamId, CarbonInterface $from, CarbonInterface $to): array
    {
        $proposals = $this->countByStatus('action_proposals', $teamId, $from, $to);
        $approvals = $this->countByStatus('approval_requests', $teamId, $from, $to);

        $gaps = [];
        if (array_sum($proposals) + array_sum($approvals) === 0) {
            $gaps[] = 'No human approval decisions were recorded in this period.';
        }

        return $this->section('human_oversight', 'Art. 14', 'Human oversight', [
            'action_proposals_by_status' => $proposals,
            'approval_requests_by_status' => $approvals,
        ], $gaps);
    }

    /**
     * @return array{key: string, article: string, title: string, status: string, evidence: array<string, mixed>, gaps: list<string>}
     */
    private function robustness(string $teamId, CarbonInterface $from, CarbonInterface $to): array
    {
        $events = DB::table('audit_entries')
            ->where('team_id', $teamId)
            ->whereBetween('created_at', [$from, $to])
            ->whereIn('event', [LogSafetyViolation::EVENT, ToolOutputGuard::AUDIT_EVENT])
            ->get(['event', 'properties']);

        $gatewayByRule = [];
        $toolByScanner = [];
        foreach ($events as $row) {
            $properties = json_decode((string) $row->properties, true) ?: [];
            if ($row->event === LogSafetyViolation::EVENT) {
                $key = (string) ($properties['rule_id'] ?? 'unknown');
                $gatewayByRule[$key] = ($gatewayByRule[$key] ?? 0) + 1;
            } else {
                $key = (string) ($properties['scanner'] ?? 'unknown');
                $toolByScanner[$key] = ($toolByScanner[$key] ?? 0) + 1;
            }
        }

        $classifierTeam = (bool) config('ai_safety.enabled')
            && (bool) (json_decode((string) DB::table('teams')->where('id', $teamId)->value('settings'), true)['safety_classifier_enabled'] ?? false);
        $toolScanOn = (bool) config('ai_safety.tool_output_scan.enabled');

        $gaps = [];
        if (! $classifierTeam) {
            $gaps[] = 'The gateway safety classifier is not enabled for this team.';
        }
        if (! $toolScanOn) {
            $gaps[] = 'Tool output scanning is off: indirect prompt injection through tool results is not detected.';
        }

        return $this->section('robustness', 'Art. 15', 'Accuracy, robustness and cybersecurity', [
            'gateway_safety_classifier_enabled' => $classifierTeam,
            'tool_output_scan_enabled' => $toolScanOn,
            'gateway_violations_by_rule' => $gatewayByRule,
            'tool_output_threats_by_scanner' => $toolByScanner,
        ], $gaps);
    }

    /**
     * @return array<string, int>
     */
    private function countByStatus(string $table, string $teamId, CarbonInterface $from, CarbonInterface $to): array
    {
        return DB::table($table)
            ->where('team_id', $teamId)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @param  list<string>  $gaps
     * @return array{key: string, article: string, title: string, status: string, evidence: array<string, mixed>, gaps: list<string>}
     */
    private function section(string $key, string $article, string $title, array $evidence, array $gaps): array
    {
        return [
            'key' => $key,
            'article' => $article,
            'title' => $title,
            'status' => $gaps === [] ? 'evidence' : 'gap',
            'evidence' => $evidence,
            'gaps' => $gaps,
        ];
    }
}
