<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Skill\Actions\CreateSkillAction;
use App\Domain\Skill\Enums\SkillType;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class CreateSkillTool implements Tool
{
    public function name(): string
    {
        return 'create_skill';
    }

    public function description(): string
    {
        return 'Create a new reusable skill. Type must be one of: llm, connector, rule, hybrid.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->required()->description('Skill name'),
            'type' => $schema->string()->required()->description('Skill type: llm, connector, rule, hybrid'),
            'description' => $schema->string()->description('Skill description'),
            'prompt_template' => $schema->string()->description('System prompt template for LLM-backed skills'),
        ];
    }

    public function handle(Request $request): string
    {
        try {
            $skillType = SkillType::tryFrom($request->get('type'));
            if (! $skillType) {
                return json_encode(['error' => "Invalid skill type '{$request->get('type')}'. Must be one of: llm, connector, rule, hybrid"]);
            }

            $skill = app(CreateSkillAction::class)->execute(
                teamId: auth()->user()->current_team_id,
                name: $request->get('name'),
                type: $skillType,
                description: $request->get('description', ''),
                systemPrompt: $request->get('prompt_template'),
                createdBy: auth()->id(),
            );

            return json_encode([
                'success' => true,
                'skill_id' => $skill->id,
                'name' => $skill->name,
                'status' => $skill->status->value,
                'url' => route('skills.show', $skill),
            ]);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }
}
