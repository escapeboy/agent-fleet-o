<?php

namespace App\Mcp\Tools\Memory;

use App\Domain\Memory\Enums\MemoryCategory;
use App\Domain\Memory\Jobs\ClassifyMemoryTopicJob;
use App\Domain\Memory\Models\Memory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class MemoryAddTool extends Tool
{
    protected string $name = 'memory_add';

    protected string $description = 'Manually add a memory entry. Use this to seed knowledge (e.g. already-published URLs, known facts, prior decisions) that agents should remember in future runs.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'content' => $schema->string()
                ->description('The memory text to store')
                ->required(),
            'source_type' => $schema->string()
                ->description('Origin of this memory, e.g. "manual", "observation", "instruction". Default: manual')
                ->default('manual'),
            'agent_id' => $schema->string()
                ->description('Associate this memory with a specific agent UUID (optional)'),
            'project_id' => $schema->string()
                ->description('Associate this memory with a specific project UUID (optional)'),
            'tags' => $schema->array()
                ->description('Tags for grouping and filtering memories')
                ->items($schema->string()),
            'confidence' => $schema->number()
                ->description('Confidence score 0.0–1.0. Default: 1.0 for manually added memories')
                ->default(1.0),
            'metadata' => $schema->object()
                ->description('Additional structured metadata (key-value pairs)'),
            'topic' => $schema->string()
                ->description('Named topic context, e.g. "auth_migration". Auto-classified via Haiku if omitted.'),
            'category' => $schema->string()
                ->description('Memory category: facts|events|discoveries|preferences|advice|knowledge|context|behavior|goal'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'content' => 'required|string',
            'source_type' => 'nullable|string|max:100',
            'agent_id' => 'nullable|uuid|exists:agents,id',
            'project_id' => 'nullable|uuid|exists:projects,id',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:100',
            'confidence' => 'nullable|numeric|min:0|max:1',
            'metadata' => 'nullable|array',
            'topic' => 'nullable|string|max:100',
            'category' => 'nullable|string',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;

        $content = $validated['content'];
        $topic = isset($validated['topic']) && $validated['topic'] !== '' ? $validated['topic'] : null;
        $category = isset($validated['category'])
            ? MemoryCategory::tryFrom($validated['category'])
            : null;

        $memory = Memory::create([
            'team_id' => $teamId,
            'content' => $content,
            'content_hash' => hash('sha256', mb_strtolower(trim($content))),
            'source_type' => $validated['source_type'] ?? 'manual',
            'agent_id' => $validated['agent_id'] ?? null,
            'project_id' => $validated['project_id'] ?? null,
            'tags' => $validated['tags'] ?? null,
            'confidence' => $validated['confidence'] ?? 1.0,
            'metadata' => $validated['metadata'] ?? null,
            'topic' => $topic,
            'category' => $category,
        ]);

        if ($topic === null) {
            ClassifyMemoryTopicJob::dispatch($memory->id);
        }

        return Response::text(json_encode([
            'success' => true,
            'memory_id' => $memory->id,
            'content' => mb_substr($memory->content, 0, 200),
            'source_type' => $memory->source_type,
            'tags' => $memory->tags ?? [],
            'topic' => $memory->topic,
            'category' => $memory->category?->value,
            'confidence' => $memory->confidence,
            'created_at' => $memory->created_at?->toIso8601String(),
        ]));
    }
}
