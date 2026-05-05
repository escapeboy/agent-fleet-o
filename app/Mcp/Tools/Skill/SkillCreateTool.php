<?php

namespace App\Mcp\Tools\Skill;

use App\Domain\Shared\Enums\DataClassification;
use App\Domain\Skill\Actions\CreateSkillAction;
use App\Domain\Skill\Enums\SkillType;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class SkillCreateTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'skill_create';

    protected string $description = 'Create a new skill. Specify name, type, and optionally description and prompt template.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->description('Skill name')
                ->required(),
            'type' => $schema->string()
                ->description('Skill type: llm, connector, rule, hybrid, boruna_script')
                ->enum(['llm', 'connector', 'rule', 'hybrid', 'boruna_script'])
                ->required(),
            'description' => $schema->string()
                ->description('Skill description'),
            'prompt_template' => $schema->string()
                ->description('System prompt template for LLM-backed skills'),
            'data_classification' => $schema->string()
                ->description('Data classification level: public, internal, confidential, restricted.')
                ->enum(['public', 'internal', 'confidential', 'restricted']),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|string|in:llm,connector,rule,hybrid,boruna_script',
            'description' => 'nullable|string',
            'prompt_template' => 'nullable|string',
            'data_classification' => 'nullable|string|in:public,internal,confidential,restricted',
        ]);
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        try {
            $skill = app(CreateSkillAction::class)->execute(
                teamId: $teamId,
                name: $validated['name'],
                type: SkillType::from($validated['type']),
                description: $validated['description'] ?? '',
                systemPrompt: $validated['prompt_template'] ?? null,
                createdBy: auth()->id(),
                dataClassification: isset($validated['data_classification'])
                    ? DataClassification::from($validated['data_classification'])
                    : DataClassification::Internal,
            );

            return Response::text(json_encode([
                'success' => true,
                'skill_id' => $skill->id,
                'name' => $skill->name,
                'status' => $skill->status->value,
            ]));
        } catch (\Throwable $e) {
            throw $e;
        }
    }
}
