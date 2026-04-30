<?php

namespace App\Mcp\Tools\AuditConsole;

use App\Mcp\Concerns\HasStructuredErrors;
use FleetQ\BorunaAudit\Enums\DecisionStatus;
use FleetQ\BorunaAudit\Models\AuditableDecision;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool as McpTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class AuditConsoleListDecisionsTool extends McpTool
{
    use HasStructuredErrors;

    protected string $name = 'audit_console_list_decisions';

    protected string $description = 'List Boruna auditable decisions for the current team. Supports filtering by workflow name, status, and date range. Returns cursor-paginated results.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'workflow_name' => $schema->string()
                ->description('Filter by workflow name (e.g. driver_scoring, route_approval, incident_classification)'),
            'status' => $schema->string()
                ->description('Filter by decision status')
                ->enum(array_column(DecisionStatus::cases(), 'value')),
            'date_from' => $schema->string()
                ->description('Filter decisions created on or after this date (YYYY-MM-DD)'),
            'date_to' => $schema->string()
                ->description('Filter decisions created on or before this date (YYYY-MM-DD)'),
            'cursor' => $schema->string()
                ->description('Cursor for pagination (from previous response)'),
            'per_page' => $schema->number()
                ->description('Results per page (default 25, max 100)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? null;

        $query = AuditableDecision::where('team_id', $teamId)
            ->orderByDesc('created_at');

        if ($workflowName = $request->get('workflow_name')) {
            $query->where('workflow_name', $workflowName);
        }

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        if ($dateFrom = $request->get('date_from')) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }

        if ($dateTo = $request->get('date_to')) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        $perPage = min((int) ($request->get('per_page') ?? 25), 100);
        $decisions = $query->cursorPaginate($perPage);

        return Response::text(json_encode([
            'data' => $decisions->map(fn ($d) => [
                'id' => $d->id,
                'workflow_name' => $d->workflow_name,
                'workflow_version' => $d->workflow_version,
                'run_id' => $d->run_id,
                'status' => $d->status->value,
                'shadow_mode' => $d->shadow_mode,
                'bundle_path' => $d->bundle_path,
                'created_at' => $d->created_at->toIso8601String(),
            ]),
            'next_cursor' => $decisions->nextCursor()?->encode(),
        ]));
    }
}
