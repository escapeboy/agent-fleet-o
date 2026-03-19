<?php

namespace App\Mcp\Tools\Skill;

use App\Domain\Skill\Actions\DeleteSkillAction;
use App\Domain\Skill\Models\Skill;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class SkillDeleteTool extends Tool
{
    protected string $name = 'skill_delete';

    protected string $description = 'Delete a skill (soft delete). Cannot delete skills with active executions.';

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
        $validated = $request->validate([
            'skill_id' => 'required|string',
        ]);

        $skill = Skill::find($validated['skill_id']);

        if (! $skill) {
            return Response::error('Skill not found.');
        }

        try {
            app(DeleteSkillAction::class)->execute($skill);

            return Response::text(json_encode([
                'success' => true,
                'skill_id' => $validated['skill_id'],
                'deleted' => true,
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
