<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Signal\Models\Signal;
use App\Domain\Trigger\Actions\CreateTriggerRuleAction;
use App\Domain\Trigger\Actions\DeleteTriggerRuleAction;
use App\Domain\Trigger\Actions\UpdateTriggerRuleAction;
use App\Domain\Trigger\Enums\TriggerRuleStatus;
use App\Domain\Trigger\Models\TriggerRule;
use App\Domain\Trigger\Services\TriggerConditionEvaluator;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\TriggerRuleResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * @tags Triggers
 */
class TriggerController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $triggers = QueryBuilder::for(TriggerRule::class)
            ->allowedFilters([
                AllowedFilter::exact('status'),
                AllowedFilter::exact('project_id'),
                AllowedFilter::partial('name'),
            ])
            ->allowedSorts(['created_at', 'name', 'total_triggers', 'last_triggered_at'])
            ->defaultSort('-created_at')
            ->cursorPaginate(min((int) $request->input('per_page', 15), 100));

        return TriggerRuleResource::collection($triggers);
    }

    public function show(TriggerRule $trigger): TriggerRuleResource
    {
        return new TriggerRuleResource($trigger);
    }

    public function store(Request $request, CreateTriggerRuleAction $action): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'project_id' => ['sometimes', 'nullable', 'string', 'exists:projects,id'],
            'source_type' => ['sometimes', 'string', 'max:64'],
            'conditions' => ['sometimes', 'nullable', 'array'],
            'input_mapping' => ['sometimes', 'nullable', 'array'],
            'cooldown_seconds' => ['sometimes', 'integer', 'min:0'],
            'max_concurrent' => ['sometimes', 'integer', 'min:1'],
        ]);

        $trigger = $action->execute(
            teamId: $request->user()->current_team_id,
            name: $request->name,
            sourceType: $request->input('source_type', '*'),
            projectId: $request->project_id,
            conditions: $request->input('conditions'),
            inputMapping: $request->input('input_mapping'),
            cooldownSeconds: (int) $request->input('cooldown_seconds', 0),
            maxConcurrent: (int) $request->input('max_concurrent', 1),
        );

        return (new TriggerRuleResource($trigger))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, TriggerRule $trigger, UpdateTriggerRuleAction $action): TriggerRuleResource
    {
        $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'project_id' => ['sometimes', 'nullable', 'string', 'exists:projects,id'],
            'source_type' => ['sometimes', 'string', 'max:64'],
            'conditions' => ['sometimes', 'nullable', 'array'],
            'input_mapping' => ['sometimes', 'nullable', 'array'],
            'cooldown_seconds' => ['sometimes', 'integer', 'min:0'],
            'max_concurrent' => ['sometimes', 'integer', 'min:1'],
        ]);

        $trigger = $action->execute($trigger, $request->only([
            'name', 'project_id', 'source_type', 'conditions',
            'input_mapping', 'cooldown_seconds', 'max_concurrent',
        ]));

        return new TriggerRuleResource($trigger);
    }

    /**
     * @response 200 {"message": "Trigger rule deleted."}
     */
    public function destroy(TriggerRule $trigger, DeleteTriggerRuleAction $action): JsonResponse
    {
        $action->execute($trigger);

        return response()->json(['message' => 'Trigger rule deleted.']);
    }

    public function toggleStatus(TriggerRule $trigger): TriggerRuleResource
    {
        $trigger->update([
            'status' => $trigger->status === TriggerRuleStatus::Active
                ? TriggerRuleStatus::Paused
                : TriggerRuleStatus::Active,
        ]);

        return new TriggerRuleResource($trigger->fresh());
    }

    /**
     * Dry-run the trigger rule against a sample signal payload.
     *
     * @response 200 {"matched": true, "rule_id": "uuid", "rule_name": "..."}
     */
    public function test(Request $request, TriggerRule $trigger): JsonResponse
    {
        $request->validate([
            'source_type' => ['sometimes', 'string', 'max:64'],
            'payload' => ['sometimes', 'array'],
        ]);

        $sourceType = $request->input('source_type', 'manual');
        $payload = $request->input('payload', []);

        // Build a transient Signal model (not persisted) for evaluation.
        $signal = new Signal([
            'team_id' => $trigger->team_id,
            'source_type' => $sourceType,
            'payload' => $payload,
        ]);

        $evaluator = app(TriggerConditionEvaluator::class);
        $matched = $trigger->matchesSourceType($sourceType)
            && $evaluator->evaluate($trigger->conditions, $signal);

        return response()->json([
            'matched' => $matched,
            'rule_id' => $trigger->id,
            'rule_name' => $trigger->name,
        ]);
    }
}
