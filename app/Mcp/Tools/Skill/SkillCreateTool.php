<?php

namespace App\Mcp\Tools\Skill;

use App\Domain\Skill\Actions\CreateSkillAction;
use App\Domain\Skill\Enums\SkillType;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class SkillCreateTool extends Tool
{
    protected string $name = 'skill_create';

    protected string $description = 'Create a new skill. Specify name, type, and optionally description and prompt template.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->description('Skill name')
                ->required(),
            'type' => $schema->string()
                ->description('Skill type: llm, connector, rule, hybrid')
                ->enum(['llm', 'connector', 'rule', 'hybrid'])
                ->required(),
            'description' => $schema->string()
                ->description('Skill description'),
            'prompt_template' => $schema->string()
                ->description('System prompt template for LLM-backed skills'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|string|in:llm,connector,rule,hybrid',
            'description' => 'nullable|string',
            'prompt_template' => 'nullable|string',
        ]);

        try {
            $skill = app(CreateSkillAction::class)->execute(
                teamId: auth()->user()->current_team_id,
                name: $validated['name'],
                type: SkillType::from($validated['type']),
                description: $validated['description'] ?? '',
                systemPrompt: $validated['prompt_template'] ?? null,
                createdBy: auth()->id(),
            );

            return Response::text(json_encode([
                'success' => true,
                'skill_id' => $skill->id,
                'name' => $skill->name,
                'status' => $skill->status->value,
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
