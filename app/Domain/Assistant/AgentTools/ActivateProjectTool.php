<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Project\Enums\ProjectStatus;
use App\Domain\Project\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class ActivateProjectTool implements Tool
{
    public function name(): string
    {
        return 'activate_project';
    }

    public function description(): string
    {
        return 'Activate a draft or failed project so it can run. The project must be in draft or failed status.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->string()->required()->description('The project UUID'),
        ];
    }

    public function handle(Request $request): string
    {
        $project = Project::find($request->get('project_id'));
        if (! $project) {
            return json_encode(['error' => 'Project not found']);
        }

        if (! $project->status->canTransitionTo(ProjectStatus::Active)) {
            return json_encode(['error' => "Cannot activate project in '{$project->status->value}' status."]);
        }

        try {
            DB::transaction(function () use ($project) {
                $project->update(['status' => ProjectStatus::Active]);
                if ($project->schedule) {
                    $project->schedule->update(['enabled' => true]);
                }
            });

            $project->refresh();

            return json_encode([
                'success' => true,
                'project_id' => $project->id,
                'title' => $project->title,
                'status' => $project->status->value,
                'url' => route('projects.show', $project),
            ]);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }
}
