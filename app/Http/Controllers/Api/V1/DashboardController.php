<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Agent\Models\Agent;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Skill\Models\Skill;
use App\Domain\Workflow\Models\Workflow;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @tags Dashboard
 */
class DashboardController extends Controller
{
    /**
     * @response 200 {"data": {"experiments_count": 42, "active_experiments": 5, "agents_count": 10, "active_agents": 8, "skills_count": 20, "workflows_count": 6}}
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'experiments_count' => Experiment::count(),
                'active_experiments' => Experiment::whereNotIn('status', ['completed', 'killed', 'discarded'])->count(),
                'agents_count' => Agent::count(),
                'active_agents' => Agent::where('status', 'active')->count(),
                'skills_count' => Skill::count(),
                'workflows_count' => Workflow::count(),
            ],
        ]);
    }
}
