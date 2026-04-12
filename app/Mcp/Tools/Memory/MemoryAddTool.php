<?php

namespace App\Mcp\Tools\Memory;

use App\Domain\Memory\Actions\StoreMemoryAction;
use App\Domain\Memory\Enums\MemoryCategory;
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
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::text(json_encode(['error' => 'No team context']));
        }

        $validated = $request->validate([
            'content' => 'required|string',
            'source_type' => 'nullable|string|max:100',
            'agent_id' => "nullable|uuid|exists:agents,id,team_id,{$teamId}",
            'project_id' => "nullable|uuid|exists:projects,id,team_id,{$teamId}",
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:100',
            'confidence' => 'nullable|numeric|min:0|max:1',
            'metadata' => 'nullable|array',
            'topic' => 'nullable|string|max:100',
            'category' => 'nullable|string',
        ]);

        $topic = isset($validated['topic']) && $validated['topic'] !== '' ? $validated['topic'] : null;
        $category = isset($validated['category'])
            ? MemoryCategory::tryFrom($validated['category'])
            : null;

        $stored = app(StoreMemoryAction::class)->execute(
            teamId: $teamId,
            agentId: $validated['agent_id'] ?? null,
            content: $validated['content'],
            sourceType: $validated['source_type'] ?? 'manual',
            projectId: $validated['project_id'] ?? null,
            metadata: $validated['metadata'] ?? [],
            confidence: $validated['confidence'] ?? 1.0,
            tags: $validated['tags'] ?? [],
            category: $category,
            topic: $topic,
        );

        $memory = $stored[0] ?? null;

        return Response::text(json_encode([
            'success' => true,
            'memory_id' => $memory?->id,
            'content' => mb_substr($validated['content'], 0, 200),
            'source_type' => $validated['source_type'] ?? 'manual',
            'tags' => $validated['tags'] ?? [],
            'topic' => $topic,
            'category' => $category?->value,
            'confidence' => $validated['confidence'] ?? 1.0,
        ]));
    }
}
