<?php

namespace App\Mcp\Tools\Memory;

use App\Domain\Memory\Actions\StoreMemoryAction;
use App\Domain\Memory\Enums\MemoryBeliefStatus;
use App\Domain\Memory\Enums\MemoryBeliefType;
use App\Domain\Memory\Enums\MemoryCategory;
use App\Domain\Memory\Enums\MemoryPreferenceSubtype;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
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
            'belief_type' => $schema->string()
                ->description('Structured belief type: preference|decision|entity|relation|open_question')
                ->enum(['preference', 'decision', 'entity', 'relation', 'open_question']),
            'preference_subtype' => $schema->string()
                ->description('Only valid when belief_type=preference: expertise (depth calibration) or style (tone/format)')
                ->enum(['expertise', 'style']),
            'why_it_matters' => $schema->string()
                ->description('A single actionable directive describing how a future run should behave because of this fact.'),
            'belief_status' => $schema->string()
                ->description('Belief lifecycle: active (default, explicitly stated), inferred (derived, awaits confirmation), exploratory (being considered), superseded (replaced, never injected)')
                ->enum(['active', 'inferred', 'exploratory', 'superseded'])
                ->default('active'),
            'domain' => $schema->string()
                ->description('Scope tag so the belief only surfaces in matching sessions, e.g. "domain:code", "domain:writing", or "user:universal" for everywhere.'),
            'rejected_alternatives' => $schema->array()
                ->description('Options that were considered and ruled out. Each item is an object {"option": "...", "reason": "..."}. Stored as structured data and surfaced to future agents so they do not re-propose a vetoed approach.')
                ->items($schema->object()),
            'supersedes_id' => $schema->string()
                ->description('UUID of an existing memory this one replaces. The superseded memory is kept for audit but its belief_status flips to "superseded" so it is never injected again.'),
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
            'belief_type' => 'nullable|string|in:preference,decision,entity,relation,open_question',
            'preference_subtype' => 'nullable|string|in:expertise,style',
            'why_it_matters' => 'nullable|string|max:2000',
            'belief_status' => 'nullable|string|in:active,inferred,exploratory,superseded',
            'domain' => 'nullable|string|max:64',
            'rejected_alternatives' => 'nullable|array',
            'rejected_alternatives.*.option' => 'required_with:rejected_alternatives|string|max:200',
            'rejected_alternatives.*.reason' => 'nullable|string|max:500',
            'supersedes_id' => "nullable|uuid|exists:memories,id,team_id,{$teamId}",
        ]);

        $topic = isset($validated['topic']) && $validated['topic'] !== '' ? $validated['topic'] : null;
        $category = isset($validated['category'])
            ? MemoryCategory::tryFrom($validated['category'])
            : null;
        $beliefType = isset($validated['belief_type'])
            ? MemoryBeliefType::tryFrom($validated['belief_type'])
            : null;
        $preferenceSubtype = isset($validated['preference_subtype'])
            ? MemoryPreferenceSubtype::tryFrom($validated['preference_subtype'])
            : null;
        $beliefStatus = isset($validated['belief_status'])
            ? (MemoryBeliefStatus::tryFrom($validated['belief_status']) ?? MemoryBeliefStatus::Active)
            : MemoryBeliefStatus::Active;
        $whyItMatters = isset($validated['why_it_matters']) && $validated['why_it_matters'] !== ''
            ? $validated['why_it_matters'] : null;
        $domain = isset($validated['domain']) && $validated['domain'] !== '' ? $validated['domain'] : null;

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
            beliefType: $beliefType,
            preferenceSubtype: $preferenceSubtype,
            whyItMatters: $whyItMatters,
            beliefStatus: $beliefStatus,
            domain: $domain,
            rejectedAlternatives: $validated['rejected_alternatives'] ?? [],
            supersedesId: $validated['supersedes_id'] ?? null,
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
            'belief_type' => $beliefType?->value,
            'preference_subtype' => $preferenceSubtype?->value,
            'belief_status' => $beliefStatus->value,
            'domain' => $domain,
            'rejected_alternatives' => $memory?->rejected_alternatives ?? [],
            'supersedes_id' => $validated['supersedes_id'] ?? null,
            'confidence' => $validated['confidence'] ?? 1.0,
        ]));
    }
}
