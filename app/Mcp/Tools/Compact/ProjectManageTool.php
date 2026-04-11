<?php

namespace App\Mcp\Tools\Compact;

use App\Mcp\Tools\Project\ProjectActivateTool;
use App\Mcp\Tools\Project\ProjectArchiveTool;
use App\Mcp\Tools\Project\ProjectCreateTool;
use App\Mcp\Tools\Project\ProjectGetTool;
use App\Mcp\Tools\Project\ProjectListTool;
use App\Mcp\Tools\Project\ProjectPauseTool;
use App\Mcp\Tools\Project\ProjectRestartTool;
use App\Mcp\Tools\Project\ProjectResumeTool;
use App\Mcp\Tools\Project\ProjectRunGetTool;
use App\Mcp\Tools\Project\ProjectRunListTool;
use App\Mcp\Tools\Project\ProjectScheduleManageTool;
use App\Mcp\Tools\Project\ProjectScheduleNlpTool;
use App\Mcp\Tools\Project\ProjectTriggerRunTool;
use App\Mcp\Tools\Project\ProjectUpdateTool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class ProjectManageTool extends CompactTool
{
    protected string $name = 'project_manage';

    protected string $description = 'Manage projects (continuous & one-shot). Actions: list, get (project_id), create (name, type, workflow_id), update (project_id + fields), activate (project_id), pause, resume, restart, trigger_run (project_id), archive (project_id), schedule (project_id, frequency, cron), schedule_nlp (project_id, natural language schedule), run_list (project_id), run_get (project_id, run_id).';

    protected function toolMap(): array
    {
        return [
            'list' => ProjectListTool::class,
            'get' => ProjectGetTool::class,
            'create' => ProjectCreateTool::class,
            'update' => ProjectUpdateTool::class,
            'activate' => ProjectActivateTool::class,
            'pause' => ProjectPauseTool::class,
            'resume' => ProjectResumeTool::class,
            'restart' => ProjectRestartTool::class,
            'trigger_run' => ProjectTriggerRunTool::class,
            'archive' => ProjectArchiveTool::class,
            'schedule' => ProjectScheduleManageTool::class,
            'schedule_nlp' => ProjectScheduleNlpTool::class,
            'run_list' => ProjectRunListTool::class,
            'run_get' => ProjectRunGetTool::class,
        ];
    }
}
