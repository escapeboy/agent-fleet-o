<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Memory\Models\Memory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class UploadMemoryKnowledgeTool implements Tool
{
    public function name(): string
    {
        return 'upload_memory_knowledge';
    }

    public function description(): string
    {
        return 'Store a new knowledge item in memory. Useful for injecting domain knowledge or reference material that agents can recall.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'content' => $schema->string()->required()->description('The knowledge content to store'),
            'agent_id' => $schema->string()->description('Optional agent UUID to associate this memory with'),
            'source_type' => $schema->string()->description('Source category label (default: manual_upload)'),
        ];
    }

    public function handle(Request $request): string
    {
        $teamId = auth()->user()?->current_team_id;

        if (! $teamId) {
            return json_encode(['error' => 'No current team.']);
        }

        try {
            $memory = Memory::create([
                'team_id' => $teamId,
                'agent_id' => $request->get('agent_id'),
                'content' => trim($request->get('content')),
                'source_type' => $request->get('source_type', 'manual_upload'),
                'metadata' => ['uploaded_by' => auth()->id(), 'uploaded_at' => now()->toIso8601String()],
            ]);

            return json_encode([
                'success' => true,
                'memory_id' => $memory->id,
                'source_type' => $memory->source_type,
                'content_length' => strlen($memory->content),
            ]);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }
}
