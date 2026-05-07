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

    protected string $description = <<<'TXT'
Projects — recurring (continuous) or one-shot containers that schedule workflow runs against goals. Continuous projects spawn `ProjectRun`s on the configured cadence (cron-style or natural language); one-shot projects fire once on `trigger_run`. Lifecycle states: draft → active → (paused | archived). Each run reserves budget independently.

Actions:
- list / get (read).
- create (write) — name, type (one_shot | continuous), workflow_id.
- update (write) — project_id + any creatable field.
- activate / pause / resume / restart (write) — project_id. Lifecycle transitions.
- trigger_run (write — costs credits) — project_id. Dispatches a single run immediately.
- archive (write) — project_id. Stops scheduling and hides from default lists; reversible via `restart`.
- schedule (write) — project_id, frequency or cron expression.
- schedule_nlp (write) — project_id, natural language schedule (e.g. "every weekday at 9am"). Calls LLM, costs credits.
- run_list (read) — project_id; optional limit, status filter.
- run_get (read) — project_id, run_id.
TXT;

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
