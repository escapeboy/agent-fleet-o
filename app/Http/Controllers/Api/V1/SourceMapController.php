<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Signal\Actions\UploadSourceMapAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @tags Bug Reports
 */
class SourceMapController extends Controller
{
    /**
     * Upload a JavaScript source map for a project release.
     *
     * Accepts the raw .map file as JSON body, or a multipart upload.
     *
     * @response 201 {"id": "uuid", "project": "string", "release": "string"}
     */
    public function store(Request $request, UploadSourceMapAction $action): JsonResponse
    {
        $request->validate([
            'project' => ['required', 'string', 'max:100'],
            'release' => ['required', 'string', 'max:100'],
            'source_map' => ['required_without:map_data', 'file', 'mimes:json,map', 'max:51200'],
            'map_data' => ['required_without:source_map', 'array'],
        ]);

        if ($request->hasFile('source_map')) {
            $mapData = json_decode($request->file('source_map')->get(), true);

            if (! is_array($mapData)) {
                return response()->json(['error' => 'Invalid source map JSON'], 422);
            }
        } else {
            $mapData = $request->input('map_data');
        }

        $teamId = $request->user()->current_team_id;

        $sourceMap = $action->execute(
            teamId: $teamId,
            project: $request->input('project'),
            release: $request->input('release'),
            mapData: $mapData,
        );

        return response()->json([
            'id' => $sourceMap->id,
            'project' => $sourceMap->project,
            'release' => $sourceMap->release,
        ], 201);
    }
}
