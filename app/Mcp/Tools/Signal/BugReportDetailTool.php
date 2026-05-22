<?php

namespace App\Mcp\Tools\Signal;

use App\Domain\Signal\Models\BugReportProjectConfig;
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
class BugReportDetailTool extends Tool
{
    protected string $name = 'bug_report_detail';

    protected string $description = 'Get full details of a bug report: description, logs, screenshot URL, resolved errors, suspect files, agent instructions, comments, and metadata.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'signal_id' => $schema->string()
                ->description('UUID of the bug report signal'),
        ];
    }

    public function handle(Request $request): Response
    {
        $signal = Signal::where('source_type', 'bug_report')
            ->with('comments.user')
            ->find($request->get('signal_id'));

        if (! $signal) {
            return Response::text(json_encode(['error' => 'Bug report not found']));
        }

        $payload = $signal->payload ?? [];

        $projectConfig = BugReportProjectConfig::where('team_id', $signal->team_id)
            ->where('project', $signal->project_key)
            ->first();

        return Response::text(json_encode([
            'id' => $signal->id,
            'project' => $signal->project_key,
            'status' => $signal->status?->value,
            'title' => $payload['title'] ?? null,
            'description' => $payload['description'] ?? null,
            'severity' => $payload['severity'] ?? null,
            'url' => $payload['url'] ?? null,
            'reporter_id' => $payload['reporter_id'] ?? null,
            'reporter_name' => $payload['reporter_name'] ?? null,
            'environment' => $payload['environment'] ?? null,
            'browser' => $payload['browser'] ?? null,
            'viewport' => $payload['viewport'] ?? null,
            'screenshot_url' => $signal->getFirstMediaUrl('bug_report_files'),
            'action_log' => $payload['action_log'] ?? [],
            'console_log' => $payload['console_log'] ?? [],
            'network_log' => $payload['network_log'] ?? [],
            // Enriched fields (populated asynchronously after ingestion)
            'resolved_errors' => $payload['resolved_errors'] ?? [],
            'suspect_files' => $payload['suspect_files'] ?? [],
            'source_hints' => $payload['source_hints'] ?? [],
            'agent_instructions' => $projectConfig->config ?? [],
            'ai_extracted' => $signal->metadata['ai_extracted'] ?? null,
            'experiment_id' => $signal->experiment_id,
            'comments' => $signal->comments->map(fn ($c) => [
                'id' => $c->id,
                'author' => $c->author_type === 'agent' ? 'agent' : ($c->user->name ?? 'human'),
                'body' => $c->body,
                'created_at' => $c->created_at?->toISOString(),
            ])->toArray(),
            'created_at' => $signal->created_at?->toISOString(),
        ]));
    }
}
