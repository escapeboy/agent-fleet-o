<?php

namespace App\Http\Controllers\Api;

use App\Domain\Experiment\Models\Experiment;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExperimentApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $experiments = Experiment::query()
            ->where('team_id', $request->user()->current_team_id)
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return response()->json($experiments);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $experiment = Experiment::query()
            ->where('team_id', $request->user()->current_team_id)
            ->findOrFail($id);

        $experiment->load('stages');

        return response()->json($experiment);
    }
}
