<?php

namespace App\Http\Controllers\Api;

use App\Domain\Signal\Models\Signal;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SignalApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $signals = Signal::query()
            ->where('team_id', $request->user()->current_team_id)
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return response()->json($signals);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'source' => 'required|string|max:255',
            'content' => 'required|array',
        ]);

        $signal = Signal::create([
            'team_id' => $request->user()->current_team_id,
            'title' => $validated['title'],
            'source' => $validated['source'],
            'content' => $validated['content'],
            'received_at' => now(),
        ]);

        return response()->json($signal, 201);
    }
}
