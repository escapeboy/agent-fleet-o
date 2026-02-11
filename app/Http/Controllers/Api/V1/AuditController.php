<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Models\AuditEntry;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AuditEntryResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class AuditController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $entries = QueryBuilder::for(AuditEntry::class)
            ->allowedFilters([
                AllowedFilter::exact('event'),
                AllowedFilter::exact('user_id'),
                AllowedFilter::exact('subject_type'),
                AllowedFilter::exact('subject_id'),
            ])
            ->allowedSorts(['created_at'])
            ->defaultSort('-created_at')
            ->cursorPaginate($request->input('per_page', 25));

        return AuditEntryResource::collection($entries);
    }
}
