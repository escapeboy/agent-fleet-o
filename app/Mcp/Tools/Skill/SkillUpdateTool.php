<?php

namespace App\Mcp\Tools\Skill;

use App\Domain\Skill\Actions\UpdateSkillAction;
use App\Domain\Skill\Models\Skill;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class SkillUpdateTool extends Tool
{
    protected string $name = 'skill_update';

    protected string $description = 'Update an existing skill. Only provided fields will be changed.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'skill_id' => $schema->string()
                ->description('The skill UUID')
                ->required(),
            'name' => $schema->string()
                ->description('New skill name'),
            'description' => $schema->string()
                ->description('New skill description'),
            'prompt_template' => $schema->string()
                ->description('New system prompt template'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'skill_id' => 'required|string',
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'prompt_template' => 'nullable|string',
        ]);

        $skill = Skill::find($validated['skill_id']);

        if (! $skill) {
            return Response::error('Skill not found.');
        }

        $attributes = array_filter([
            'name' => $validated['name'] ?? null,
            'description' => $validated['description'] ?? null,
            'system_prompt' => $validated['prompt_template'] ?? null,
        ], fn ($v) => $v !== null);

        if (empty($attributes)) {
            return Response::error('No fields to update. Provide at least one of: name, description, prompt_template.');
        }

        try {
            $result = app(UpdateSkillAction::class)->execute(
                skill: $skill,
                attributes: $attributes,
                updatedBy: auth()->id(),
            );

            return Response::text(json_encode([
                'success' => true,
                'skill_id' => $result->id,
                'updated_fields' => array_keys($attributes),
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
