<?php

namespace App\Mcp\Tools\Skill;

use App\Domain\Skill\Models\Skill;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class SkillGetTool extends Tool
{
    protected string $name = 'skill_get';

    protected string $description = 'Get detailed information about a specific skill including description, prompt template, risk level, and execution type.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'skill_id' => $schema->string()
                ->description('The skill UUID')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['skill_id' => 'required|string']);

        $skill = Skill::find($validated['skill_id']);

        if (! $skill) {
            return Response::error('Skill not found.');
        }

        $promptTemplate = $skill->configuration['prompt_template'] ?? $skill->system_prompt;
        $promptPreview = $promptTemplate
            ? mb_substr((string) $promptTemplate, 0, 500)
            : null;

        return Response::text(json_encode([
            'id' => $skill->id,
            'name' => $skill->name,
            'type' => $skill->type->value,
            'status' => $skill->status->value,
            'description' => $skill->description,
            'prompt_template' => $promptPreview,
            'risk_level' => $skill->risk_level?->value,
            'execution_type' => $skill->execution_type?->value,
            'created_at' => $skill->created_at?->toIso8601String(),
        ]));
    }
}
