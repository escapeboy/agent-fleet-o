<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Signal\Connectors\BugReportConnector;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;

/**
 * @tags Bug Reports
 */
class BugReportSignalController extends Controller
{
    /**
     * Submit a bug report from the QA widget.
     *
     * Accepts multipart/form-data with screenshot and optional attachment.
     *
     * @response 201 {"signal_id": "uuid", "status": "received", "url": "https://..."}
     * @response 422 {"message": "Validation error.", "errors": {}}
     */
    public function store(Request $request, BugReportConnector $connector): JsonResponse
    {
        $request->validate([
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

        $files = array_filter([
            $request->file('screenshot'),
            $request->file('additional_file'),
        ]);

        $signals = $connector->poll([
            'team_id' => $request->user()?->current_team_id,
            'project_key' => $request->input('project'),
            'files' => array_values($files),
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
            ],
        ]);

        if (empty($signals)) {
            return response()->json(['message' => 'Signal was deduplicated or rate-limited.'], 200);
        }

        $signal = $signals[0];

        return response()->json([
            'signal_id' => $signal->id,
            'status' => $signal->status?->value ?? 'received',
            'url' => URL::to('/bug-reports/'.$signal->id),
        ], 201);
    }
}
