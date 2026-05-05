<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Evolution\Actions\ApplyEvolutionProposalAction;
use App\Domain\Evolution\Enums\EvolutionProposalStatus;
use App\Domain\Evolution\Models\EvolutionProposal;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\EvolutionProposalResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * @tags Evolution
 */
class EvolutionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $proposals = QueryBuilder::for(EvolutionProposal::class)
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('agent_id'),
            )
            ->allowedSorts('created_at', 'confidence_score')
            ->defaultSort('-created_at')
            ->cursorPaginate(min((int) $request->input('per_page', 15), 100));

        return EvolutionProposalResource::collection($proposals);
    }

    public function show(EvolutionProposal $evolution): EvolutionProposalResource
    {
        return new EvolutionProposalResource($evolution);
    }

    public function apply(Request $request, EvolutionProposal $evolution, ApplyEvolutionProposalAction $action): JsonResponse
    {
        if ($evolution->status !== EvolutionProposalStatus::Pending
            && $evolution->status !== EvolutionProposalStatus::Approved) {
            return response()->json([
                'message' => "Cannot apply a proposal with status [{$evolution->status->value}].",
            ], 409);
        }

        // Auto-approve then apply if still pending
        if ($evolution->status === EvolutionProposalStatus::Pending) {
            $evolution->update([
                'status' => EvolutionProposalStatus::Approved,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);
        }

        $agent = $action->execute($evolution->fresh(), $request->user()->id);

        return response()->json([
            'success' => true,
            'proposal_id' => $evolution->id,
            'agent_id' => $agent->id,
        ]);
    }

    public function reject(Request $request, EvolutionProposal $evolution): JsonResponse
    {
        if ($evolution->status !== EvolutionProposalStatus::Pending) {
            return response()->json([
                'message' => "Cannot reject a proposal with status [{$evolution->status->value}].",
            ], 409);
        }

        $evolution->update([
            'status' => EvolutionProposalStatus::Rejected,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'proposal_id' => $evolution->id,
        ]);
    }
}
