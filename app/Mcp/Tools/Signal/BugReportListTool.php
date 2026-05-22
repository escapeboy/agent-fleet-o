<?php

namespace App\Mcp\Tools\Signal;

use App\Domain\Signal\Models\Signal;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class BugReportListTool extends Tool
{
    protected string $name = 'bug_report_list';

    protected string $description = 'List bug reports filtered by project, status, or severity. Returns id, title, severity, status, reporter, project, created_at.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_key' => $schema->string()
                ->description('Filter by project key (e.g. "client-platform")')
                ->nullable(),
            'status' => $schema->string()
                ->description('Filter by status: received, triaged, in_progress, delegated_to_agent, agent_fixing, review, resolved, dismissed')
                ->nullable(),
            'severity' => $schema->string()
                ->description('Filter by severity: critical, major, minor, cosmetic')
                ->nullable(),
            'limit' => $schema->integer()
                ->description('Max results (default 20, max 100)')
                ->default(20),
        ];
    }

    public function handle(Request $request): Response
    {
        $limit = min((int) ($request->get('limit', 20)), 100);

        $reports = Signal::query()
            ->where('source_type', 'bug_report')
            ->when($request->get('project_key'), fn ($q, $v) => $q->where('project_key', $v))
            ->when($request->get('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->get('severity'), fn ($q, $v) => $q->whereJsonContains('tags', $v))
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return Response::text(json_encode([
            'count' => $reports->count(),
            'reports' => $reports->map(fn ($r) => [
                'id' => $r->id,
                'title' => $r->payload['title'] ?? null,
                'severity' => $r->payload['severity'] ?? null,
                'status' => $r->status?->value,
                'project' => $r->project_key,
                'reporter' => $r->payload['reporter_name'] ?? null,
                'created_at' => $r->created_at?->toISOString(),
            ])->toArray(),
        ]));
    }
}
