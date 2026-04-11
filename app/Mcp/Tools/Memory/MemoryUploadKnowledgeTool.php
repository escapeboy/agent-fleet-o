<?php

namespace App\Mcp\Tools\Memory;

use App\Domain\Memory\Models\Memory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class MemoryUploadKnowledgeTool extends Tool
{
    protected string $name = 'memory_upload_knowledge';

    protected string $description = 'Store a new knowledge item in agent memory. Useful for injecting domain knowledge, facts, or reference material that agents should recall during execution.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'content' => $schema->string()
                ->description('The knowledge content to store in memory')
                ->required(),
            'agent_id' => $schema->string()
                ->description('Optional agent UUID to associate this memory with a specific agent'),
            'project_id' => $schema->string()
                ->description('Optional project UUID to associate this memory with a specific project'),
            'source_type' => $schema->string()
                ->description('Source type label for categorization (e.g. knowledge_base, manual_upload, documentation)'),
            'tags' => $schema->object()
                ->description('Optional metadata tags as a key-value object'),
            'confidence' => $schema->number()
                ->description('Confidence score for this knowledge item (0.0–1.0, default 1.0 for manually uploaded facts)')
                ->default(1.0),
        ];
    }

    public function handle(Request $request): Response
    {
        $user = Auth::user();
        $teamId = app('mcp.team_id') ?? $user?->current_team_id;

        if (! $teamId) {
            return Response::error('No current team.');
        }

        $content = $request->get('content');
        if (! $content || strlen(trim($content)) === 0) {
            return Response::error('content is required and must not be empty.');
        }

        $metadata = array_filter([
            'tags' => $request->get('tags'),
            'uploaded_by' => $user?->id,
            'uploaded_at' => now()->toIso8601String(),
        ], fn ($v) => $v !== null);

        $confidence = min(1.0, max(0.0, (float) $request->get('confidence', 1.0)));

        try {
            $memory = Memory::create([
                'team_id' => $teamId,
                'agent_id' => $request->get('agent_id'),
                'project_id' => $request->get('project_id'),
                'content' => trim($content),
                'source_type' => $request->get('source_type', 'manual_upload'),
                'metadata' => $metadata ?: null,
                'confidence' => $confidence,
                'tags' => [],
            ]);

            return Response::text(json_encode([
                'success' => true,
                'memory_id' => $memory->id,
                'source_type' => $memory->source_type,
                'content_length' => strlen($memory->content),
                'created_at' => $memory->created_at->toIso8601String(),
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
