<?php

namespace App\Mcp\Tools\System;

use App\Domain\Agent\Models\Agent;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Project\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class DashboardKpisTool extends Tool
{
    protected string $name = 'system_dashboard_kpis';

    protected string $description = 'Get dashboard KPIs: experiment counts (total, running, completed, failed, paused), project counts (total, active), agent counts (total, active).';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        return Response::text(json_encode([
            'experiments' => [
                'total' => Experiment::query()->count(),
                'running' => Experiment::query()->where('status', 'executing')->count(),
                'completed' => Experiment::query()->where('status', 'completed')->count(),
                'failed' => Experiment::query()->whereIn('status', [
                    'scoring_failed',
                    'planning_failed',
                    'building_failed',
                    'execution_failed',
                ])->count(),
                'paused' => Experiment::query()->where('status', 'paused')->count(),
            ],
            'projects' => [
                'total' => Project::query()->count(),
                'active' => Project::query()->where('status', 'active')->count(),
            ],
            'agents' => [
                'total' => Agent::query()->count(),
                'active' => Agent::query()->where('status', 'active')->count(),
            ],
        ]));
    }
}
