<?php

namespace App\Http\Controllers;

use App\Domain\Signal\Connectors\BugReportConnector;
use App\Domain\Shared\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;

/**
 * Public widget endpoint — no Sanctum auth required.
 * Auth is via the team's widget_public_key embedded in the JS widget config.
 * CORS is open (*) because no Authorization header is used.
 */
class BugReportWidgetController extends Controller
{
    /**
     * Accept a bug report from the hosted JS widget.
     *
     * @response 201 {"signal_id": "uuid", "status": "received"}
     * @response 401 {"error": "invalid_key"}
     * @response 429 {"error": "rate_limit_exceeded"}
     * @response 422 {"message": "...", "errors": {}}
     */
    public function __invoke(Request $request, BugReportConnector $connector): JsonResponse
    {
        $team = Team::where('widget_public_key', $request->input('team_public_key'))->first();

        if (! $team) {
            return response()->json(['error' => 'invalid_key'], 401);
        }

        $key = 'widget-report:'.$team->id;
        if (! RateLimiter::attempt($key, 30, fn () => true, 60)) {
            return response()->json(['error' => 'rate_limit_exceeded'], 429);
        }

        $request->validate([
            'team_public_key' => ['required', 'string'],
            'project' => ['required', 'string', 'max:100'],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string'],
            'severity' => ['required', Rule::in(['critical', 'major', 'minor', 'cosmetic'])],
            'url' => ['required', 'url', 'max:2048'],
            'reporter_id' => ['required', 'string', 'max:255'],
            'reporter_name' => ['required', 'string', 'max:255'],
            'screenshot' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:10240'],
            'additional_file' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp,gif,pdf,txt,log,json,zip,csv', 'max:5120'],
            'action_log' => ['required', 'string'],
            'console_log' => ['required', 'string'],
            'network_log' => ['nullable', 'string'],
            'browser' => ['required', 'string', 'max:500'],
            'viewport' => ['required', 'string', 'max:50'],
            'environment' => ['required', Rule::in(['sandbox', 'production'])],
        ]);

        $files = array_values(array_filter([
            $request->file('screenshot'),
            $request->file('additional_file'),
        ]));

        $signals = $connector->poll([
            'team_id' => $team->id,
            'project_key' => $request->input('project'),
            'files' => $files,
            'payload' => [
                'project' => $request->input('project'),
                'title' => $request->input('title'),
                'description' => $request->input('description'),
                'severity' => $request->input('severity'),
                'url' => $request->input('url'),
                'reporter_id' => $request->input('reporter_id'),
                'reporter_name' => $request->input('reporter_name'),
                'action_log' => $request->input('action_log'),
                'console_log' => $request->input('console_log'),
                'network_log' => $request->input('network_log'),
                'browser' => $request->input('browser'),
                'viewport' => $request->input('viewport'),
                'environment' => $request->input('environment'),
                'widget_origin' => $request->header('Origin'),
            ],
        ]);

        if (empty($signals)) {
            return response()->json(['message' => 'Signal was deduplicated or rate-limited.'], 200);
        }

        $signal = $signals[0];

        return response()->json([
            'signal_id' => $signal->id,
            'status' => $signal->status?->value ?? 'received',
        ], 201);
    }
}
