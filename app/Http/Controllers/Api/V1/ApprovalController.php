<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Approval\Actions\ApproveAction;
use App\Domain\Approval\Actions\RejectAction;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ApprovalResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class ApprovalController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $approvals = QueryBuilder::for(ApprovalRequest::class)
            ->allowedFilters([
                AllowedFilter::exact('status'),
                AllowedFilter::exact('experiment_id'),
            ])
            ->allowedSorts(['created_at', 'expires_at', 'status'])
            ->defaultSort('-created_at')
            ->cursorPaginate($request->input('per_page', 15));

        return ApprovalResource::collection($approvals);
    }

    public function show(ApprovalRequest $approval): ApprovalResource
    {
        return new ApprovalResource($approval);
    }

    public function approve(Request $request, ApprovalRequest $approval, ApproveAction $action): ApprovalResource
    {
        $request->validate([
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $action->execute(
            approvalRequest: $approval,
            reviewerId: $request->user()->id,
            notes: $request->notes,
        );

        return new ApprovalResource($approval->fresh());
    }

    public function reject(Request $request, ApprovalRequest $approval, RejectAction $action): ApprovalResource
    {
        $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $action->execute(
            approvalRequest: $approval,
            reviewerId: $request->user()->id,
            reason: $request->reason,
            notes: $request->notes,
        );

        return new ApprovalResource($approval->fresh());
    }
}
