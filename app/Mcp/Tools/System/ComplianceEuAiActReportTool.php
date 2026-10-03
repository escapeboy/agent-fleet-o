<?php

namespace App\Mcp\Tools\System;

use App\Domain\Audit\Services\EuAiActReportBuilder;
use App\Domain\Shared\Models\Team;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class ComplianceEuAiActReportTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'compliance_eu_ai_act_report';

    protected string $description = 'Build an EU AI Act evidence report for the current team: risk management (Art. 9), record-keeping (Art. 12), transparency (Art. 13/50), human oversight (Art. 14) and robustness/cybersecurity (Art. 15). Each section lists evidence counts and gaps. Supporting evidence only, not a conformity assessment. Requires owner or admin.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()
                ->description('Start date YYYY-MM-DD (default: 90 days before "to")'),
            'to' => $schema->string()
                ->description('End date YYYY-MM-DD (default: today)'),
        ];
    }

    public function handle(Request $request): Response
    {
        if (! config('audit.compliance_report.enabled')) {
            return $this->failedPreconditionError('The compliance report is not enabled on this installation (COMPLIANCE_REPORT_ENABLED).');
        }

        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null) ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        // Team security posture: same audience as the page and the API (owner/admin).
        // Checked against the team the report is built for, which with a Passport
        // token can differ from the user's current team.
        $user = auth()->user();
        $team = Team::find($teamId);
        $canManage = match (true) {
            $user === null || $team === null => false,
            $user->current_team_id === $team->id => Gate::forUser($user)->allows('manage-team'),
            default => $user->teamRole($team)?->canManageTeam() ?? false,
        };
        if (! $canManage) {
            return $this->permissionDeniedError('The compliance report requires the manage-team permission (owner or admin).');
        }

        $validated = $request->validate([
            'from' => 'nullable|string',
            'to' => 'nullable|string',
        ]);

        try {
            [$from, $to] = EuAiActReportBuilder::resolvePeriod($validated['from'] ?? null, $validated['to'] ?? null);
        } catch (InvalidArgumentException $e) {
            return $this->invalidArgumentError($e->getMessage());
        }

        return Response::text(json_encode(app(EuAiActReportBuilder::class)->build((string) $teamId, $from, $to)));
    }
}
