<?php

namespace App\Mcp\Tools\System;

use App\Domain\Audit\Models\AuditEntry;
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
class SecretScanFindingsTool extends Tool
{
    protected string $name = 'secret_scan_findings';

    protected string $description = 'List secret-scan findings — accidentally embedded API keys/secrets detected in agent, skill, and workflow free-text fields. Returns id, subject_type, subject_id, pattern_id, pattern_name, field, acknowledged, created_at.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'subject_type' => $schema->string()
                ->description('Filter by source type: agent, skill, or workflow_node'),
            'include_acknowledged' => $schema->boolean()
                ->description('Include findings already acknowledged (default false)')
                ->default(false),
            'limit' => $schema->integer()
                ->description('Max results to return (default 20, max 100)')
                ->default(20),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;

        if (! $teamId) {
            return Response::text(json_encode(['count' => 0, 'findings' => []]));
        }

        $query = AuditEntry::withoutGlobalScopes()
            ->where('event', 'secret_detected')
            ->where('team_id', $teamId)
            ->orderByDesc('created_at');

        if ($subjectType = $request->get('subject_type')) {
            $query->where('subject_type', $subjectType);
        }

        if (! $request->get('include_acknowledged', false)) {
            $query->whereNull('properties->acknowledged_at');
        }

        $limit = min((int) ($request->get('limit', 20)), 100);

        $entries = $query->limit($limit)->get();

        return Response::text(json_encode([
            'count' => $entries->count(),
            'findings' => $entries->map(fn ($e) => [
                'id' => $e->id,
                'subject_type' => $e->subject_type,
                'subject_id' => $e->subject_id,
                'pattern_id' => $e->properties['pattern_id'] ?? null,
                'pattern_name' => $e->properties['pattern_name'] ?? null,
                'field' => $e->properties['field'] ?? null,
                'acknowledged' => ! empty($e->properties['acknowledged_at']),
                'created_at' => $e->created_at?->diffForHumans(),
            ])->toArray(),
        ]));
    }
}
