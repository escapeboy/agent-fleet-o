<?php

namespace App\Mcp\Tools\Skill;

use App\Domain\Skill\Jobs\ClusterExperimentsForSkillsJob;
use App\Domain\Skill\Services\AutoSkillCreationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class SkillAutoGenerateRunTool extends Tool
{
    protected string $name = 'skill_auto_generate_run';

    protected string $description = 'Trigger the auto-skill generation pipeline that clusters completed experiments by goal similarity and creates draft skills from recurring patterns. Use dry_run=true to preview without creating.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'dry_run' => $schema->boolean()
                ->description('If true, logs what would be created without persisting anything')
                ->default(false),
        ];
    }

    public function handle(Request $request): Response
    {
        $dryRun = (bool) $request->get('dry_run', false);

        if ($dryRun) {
            $service = app(AutoSkillCreationService::class);
            $count = $service->run(dryRun: true);

            return Response::text(json_encode([
                'status' => 'dry_run_complete',
                'skills_would_create' => $count,
            ]));
        }

        ClusterExperimentsForSkillsJob::dispatch();

        return Response::text(json_encode([
            'status' => 'dispatched',
            'message' => 'Auto-skill generation job dispatched. Draft skills will be created shortly.',
        ]));
    }
}
