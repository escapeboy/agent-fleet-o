<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Agent\Models\Agent;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Project\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class GetDashboardKpisTool implements Tool
{
    public function name(): string
    {
        return 'get_dashboard_kpis';
    }

    public function description(): string
    {
        return 'Get key performance indicators: experiment counts by status, project counts, agent counts';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): string
    {
        return json_encode([
            'experiments' => [
                'total' => Experiment::count(),
                'running' => Experiment::where('status', 'running')->count(),
                'completed' => Experiment::where('status', 'completed')->count(),
                'failed' => Experiment::where('status', 'failed')->count(),
                'paused' => Experiment::where('status', 'paused')->count(),
            ],
            'projects' => [
                'total' => Project::count(),
                'active' => Project::where('status', 'active')->count(),
            ],
            'agents' => [
                'total' => Agent::count(),
                'active' => Agent::where('status', 'active')->count(),
            ],
        ]);
    }
}
