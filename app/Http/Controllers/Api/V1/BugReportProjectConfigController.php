<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Signal\Models\BugReportProjectConfig;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @tags Bug Reports
 */
class BugReportProjectConfigController extends Controller
{
    /**
     * Get agent instructions config for a project.
     *
     * @response 200 {"project": "string", "config": {...}}
     * @response 404 {"error": "not_configured"}
     */
    public function show(Request $request, string $project): JsonResponse
    {
        $teamId = $request->user()->current_team_id;

        $config = BugReportProjectConfig::where('team_id', $teamId)
            ->where('project', $project)
            ->first();

        if (! $config) {
            return response()->json(['error' => 'not_configured'], 404);
        }

        return response()->json([
            'project' => $config->project,
            'config' => $config->config,
        ]);
    }

    /**
     * Create or update agent instructions config for a project.
     *
     * @response 200 {"project": "string", "config": {...}}
     */
    public function upsert(Request $request, string $project): JsonResponse
    {
        $request->validate([
            'config' => ['required', 'array'],
            'config.test_command' => ['nullable', 'string', 'max:500'],
            'config.lint_command' => ['nullable', 'string', 'max:500'],
            'config.build_command' => ['nullable', 'string', 'max:500'],
            'config.test_directory' => ['nullable', 'string', 'max:255'],
            'config.source_directory' => ['nullable', 'string', 'max:255'],
            'config.framework' => ['nullable', 'string', 'max:100'],
            'config.notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $teamId = $request->user()->current_team_id;

        $configModel = BugReportProjectConfig::updateOrCreate(
            ['team_id' => $teamId, 'project' => $project],
            ['config' => $request->input('config')],
        );

        return response()->json([
            'project' => $configModel->project,
            'config' => $configModel->config,
        ]);
    }
}
