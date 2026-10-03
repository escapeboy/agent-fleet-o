<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Services\EuAiActReportBuilder;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * @tags Compliance
 */
class ComplianceReportController extends Controller
{
    /**
     * EU AI Act evidence report for the current team. Query: from, to (YYYY-MM-DD).
     */
    public function euAiAct(Request $request, EuAiActReportBuilder $builder): JsonResponse
    {
        abort_unless(config('audit.compliance_report.enabled'), 404);
        Gate::authorize('manage-team');

        $validated = $request->validate([
            'from' => ['nullable', 'string'],
            'to' => ['nullable', 'string'],
        ]);

        try {
            [$from, $to] = EuAiActReportBuilder::resolvePeriod($validated['from'] ?? null, $validated['to'] ?? null);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['from' => [$e->getMessage()]]], 422);
        }

        return response()->json(['data' => $builder->build((string) $request->user()->current_team_id, $from, $to)]);
    }
}
