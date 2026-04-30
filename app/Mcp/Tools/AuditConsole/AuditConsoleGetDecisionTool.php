<?php

namespace App\Mcp\Tools\AuditConsole;

use App\Mcp\Concerns\HasStructuredErrors;
use FleetQ\BorunaAudit\Models\AuditableDecision;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool as McpTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class AuditConsoleGetDecisionTool extends McpTool
{
    use HasStructuredErrors;

    protected string $name = 'audit_console_get_decision';

    protected string $description = 'Get full details of a single Boruna auditable decision including evidence bundle and verification history.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'decision_id' => $schema->string()
                ->description('UUID of the auditable decision')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = $request->teamId();

        $decision = AuditableDecision::where('id', $request->input('decision_id'))
            ->where('team_id', $teamId)
            ->with('verifications')
            ->first();

        if ($decision === null) {
            return $this->notFound('Decision not found.');
        }

        return Response::text(json_encode([
            'id' => $decision->id,
            'workflow_name' => $decision->workflow_name,
            'workflow_version' => $decision->workflow_version,
            'run_id' => $decision->run_id,
            'status' => $decision->status->value,
            'shadow_mode' => $decision->shadow_mode,
            'shadow_discrepancy' => $decision->shadow_discrepancy,
            'bundle_path' => $decision->bundle_path,
            'outputs' => $decision->outputs,
            'evidence' => $decision->evidence,
            'verifications' => $decision->verifications->map(fn ($v) => [
                'id' => $v->id,
                'status' => $v->status->value,
                'checked_at' => $v->checked_at?->toIso8601String(),
                'latency_ms' => $v->latency_ms,
                'error_message' => $v->error_message,
            ]),
            'created_at' => $decision->created_at->toIso8601String(),
        ]));
    }
}
