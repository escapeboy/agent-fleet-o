<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Skill\Actions\UpdateSkillAction;
use App\Domain\Skill\Models\Skill;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class UpdateSkillTool implements Tool
{
    public function name(): string
    {
        return 'update_skill';
    }

    public function description(): string
    {
        return 'Update an existing skill (name, description, or prompt template)';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'skill_id' => $schema->string()->required()->description('The skill UUID'),
            'name' => $schema->string()->description('New skill name'),
            'description' => $schema->string()->description('New skill description'),
            'prompt_template' => $schema->string()->description('New system prompt template'),
        ];
    }

    public function handle(Request $request): string
    {
        $skill = Skill::find($request->get('skill_id'));
        if (! $skill) {
            return json_encode(['error' => 'Skill not found']);
        }

        try {
            $attributes = array_filter([
                'name' => $request->get('name'),
                'description' => $request->get('description'),
                'system_prompt' => $request->get('prompt_template'),
            ], fn ($v) => $v !== null);

            if (empty($attributes)) {
                return json_encode(['error' => 'No attributes provided to update']);
            }

            $skill = app(UpdateSkillAction::class)->execute(
                skill: $skill,
                attributes: $attributes,
                updatedBy: auth()->id(),
            );

            return json_encode([
                'success' => true,
                'skill_id' => $skill->id,
                'name' => $skill->name,
                'version' => $skill->current_version,
            ]);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }
}
