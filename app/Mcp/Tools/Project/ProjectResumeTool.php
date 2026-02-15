<?php

namespace App\Mcp\Tools\Project;

use App\Domain\Project\Actions\ResumeProjectAction;
use App\Domain\Project\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ProjectResumeTool extends Tool
{
    protected string $name = 'project_resume';

    protected string $description = 'Resume a paused project. Re-enables its schedule and recalculates next run.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->string()
                ->description('The project UUID')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'project_id' => 'required|string',
        ]);

        $project = Project::find($validated['project_id']);

        if (! $project) {
            return Response::error('Project not found.');
        }

        try {
            $result = app(ResumeProjectAction::class)->execute($project);

            return Response::text(json_encode([
                'success' => true,
                'project_id' => $result->id,
                'status' => $result->status->value,
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
