<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Signal\Actions\IngestSignalAction;
use App\Domain\Signal\Models\Signal;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\SignalResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * @tags Signals
 */
class SignalController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $signals = QueryBuilder::for(Signal::class)
            ->allowedFilters([
                AllowedFilter::exact('source_type'),
                AllowedFilter::exact('experiment_id'),
                AllowedFilter::partial('source_identifier'),
            ])
            ->allowedSorts(['created_at', 'score', 'received_at'])
            ->defaultSort('-created_at')
            ->cursorPaginate($request->input('per_page', 15));

        return SignalResource::collection($signals);
    }

    public function show(Signal $signal): SignalResource
    {
        return new SignalResource($signal);
    }

    /**
     * @response 200 {"message": "Signal was deduplicated or blacklisted."}
     * @response 422 {"message": "Validation error.", "errors": {"source_type": ["The source type field is required."]}}
     */
    public function store(Request $request, IngestSignalAction $action): JsonResponse
    {
        $request->validate([
            'source_type' => ['required', 'string', 'max:50'],
            'source_identifier' => ['required', 'string', 'max:255'],
            'payload' => ['required', 'array'],
            'tags' => ['sometimes', 'array'],
            'experiment_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('experiments', 'id')->where('team_id', $request->user()?->current_team_id)],
        ]);

        $signal = $action->execute(
            sourceType: $request->source_type,
            sourceIdentifier: $request->source_identifier,
            payload: $request->payload,
            tags: $request->input('tags', []),
            experimentId: $request->experiment_id,
        );

        if (! $signal) {
            return response()->json([
                'message' => 'Signal was deduplicated or blacklisted.',
            ], 200);
        }

        return (new SignalResource($signal))
            ->response()
            ->setStatusCode(201);
    }
}
