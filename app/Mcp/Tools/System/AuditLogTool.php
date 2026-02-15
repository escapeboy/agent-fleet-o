<?php

namespace App\Mcp\Tools\System;

use App\Domain\Audit\Models\AuditEntry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class AuditLogTool extends Tool
{
    protected string $name = 'system_audit_log';

    protected string $description = 'Get recent audit log entries with optional subject type filter. Returns id, subject_type, subject_id, event, description, created_at.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'subject_type' => $schema->string()
                ->description('Filter by subject type (e.g. experiment, agent, approval)'),
            'limit' => $schema->integer()
                ->description('Max results to return (default 20, max 100)')
                ->default(20),
        ];
    }

    public function handle(Request $request): Response
    {
        $query = AuditEntry::query()->orderByDesc('created_at');

        if ($subjectType = $request->get('subject_type')) {
            $query->where('subject_type', $subjectType);
        }

        $limit = min((int) ($request->get('limit', 20)), 100);

        $entries = $query->limit($limit)->get();

        return Response::text(json_encode([
            'count' => $entries->count(),
            'entries' => $entries->map(fn ($e) => [
                'id' => $e->id,
                'subject_type' => $e->subject_type,
                'subject_id' => $e->subject_id,
                'event' => $e->event,
                'description' => $e->properties
                    ? mb_substr(json_encode($e->properties), 0, 200)
                    : null,
                'created_at' => $e->created_at?->diffForHumans(),
            ])->toArray(),
        ]));
    }
}
