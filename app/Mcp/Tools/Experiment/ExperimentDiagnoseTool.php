<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Experiment;

use App\Domain\Experiment\Enums\StageStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Domain\Experiment\Models\WorklogEntry;
use App\Domain\Shared\Services\ErrorTranslator;
use App\Infrastructure\AI\Models\CircuitBreakerState;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Cache;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class ExperimentDiagnoseTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'experiment_diagnose';

    protected string $description = 'Diagnose a failed or paused experiment. Combines the latest failed-stage error, agent circuit-breaker state, and recent worklog into a customer-readable root cause with recommended recovery actions. Read-only — does not mutate state.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'experiment_id' => $schema->string()
                ->description('The experiment UUID')
                ->required(),
            'locale' => $schema->string()
                ->description('Optional 2-letter locale ("en" or "bg"). Defaults to the app locale.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'experiment_id' => 'required|string',
            'locale' => 'nullable|string|max:5',
        ]);

        $teamId = app()->bound('mcp.team_id')
            ? app('mcp.team_id')
            : auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $experiment = Experiment::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($validated['experiment_id']);

        if (! $experiment) {
            return $this->notFoundError('experiment', $validated['experiment_id']);
        }

        // 60s cache per (experiment, status, updated_at) keeps repeat clicks cheap
        // without staling out across actual state changes.
        $cacheKey = sprintf(
            'experiment_diagnose:%s:%s:%s:%s',
            $experiment->id,
            $experiment->status->value,
            $experiment->updated_at?->timestamp ?? 0,
            $validated['locale'] ?? 'auto',
        );

        $payload = Cache::remember($cacheKey, 60, fn () => $this->buildDiagnosis(
            $experiment,
            $validated['locale'] ?? null,
        ));

        return Response::text(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string, mixed> */
    private function buildDiagnosis(Experiment $experiment, ?string $locale): array
    {
        $isFailed = $experiment->status->isFailed();
        $isPaused = $experiment->status->value === 'paused';
        $isTerminalSuccess = $experiment->status->value === 'completed';

        if ($isTerminalSuccess) {
            return $this->shape(
                experimentId: $experiment->id,
                rootCause: 'no_failure_detected',
                summary: 'Experiment completed successfully — nothing to diagnose.',
                evidence: [],
                actions: [],
                confidence: 1.0,
            );
        }

        $failedStage = $this->latestFailedStage($experiment->id);
        $errorString = $this->extractErrorString($experiment, $failedStage);

        $translation = app(ErrorTranslator::class)->translate(
            technicalMessage: $errorString,
            locale: $locale,
            placeholders: [
                'experiment_id' => $experiment->id,
            ],
        );

        $evidence = $this->collectEvidence($experiment, $failedStage);

        // Confidence heuristic: matched dictionary => 0.85, fallback unknown => 0.4,
        // clean-no-error states (e.g. paused for budget) => 0.65 if we have evidence.
        $confidence = match (true) {
            $translation->matched => 0.85,
            $isPaused && ! empty($evidence) => 0.65,
            default => 0.4,
        };

        $rootCause = $translation->matched
            ? $translation->code
            : ($isFailed ? 'unknown_failure' : ($isPaused ? 'paused_no_diagnostic' : 'no_failure_detected'));

        return $this->shape(
            experimentId: $experiment->id,
            rootCause: $rootCause,
            summary: $translation->message,
            evidence: $evidence,
            actions: array_map(fn ($a) => $a->toArray(), $translation->actions),
            confidence: $confidence,
            extra: [
                'mcp_error_code' => $translation->mcpErrorCode->value,
                'retryable' => $translation->retryable,
                'experiment_status' => $experiment->status->value,
                'matched_dictionary' => $translation->matched,
            ],
        );
    }

    private function latestFailedStage(string $experimentId): ?ExperimentStage
    {
        return ExperimentStage::withoutGlobalScopes()
            ->where('experiment_id', $experimentId)
            ->where('status', StageStatus::Failed)
            ->orderByDesc('completed_at')
            ->orderByDesc('started_at')
            ->orderByDesc('iteration')
            ->first();
    }

    private function extractErrorString(Experiment $experiment, ?ExperimentStage $stage): string
    {
        if ($stage !== null) {
            $snapshot = $stage->output_snapshot ?? [];
            if (is_array($snapshot) && isset($snapshot['error']) && is_string($snapshot['error']) && $snapshot['error'] !== '') {
                return $snapshot['error'];
            }
            $telemetry = $stage->telemetry ?? [];
            if (is_array($telemetry) && isset($telemetry['error']) && is_string($telemetry['error']) && $telemetry['error'] !== '') {
                return $telemetry['error'];
            }
        }

        $meta = $experiment->meta ?? [];
        if (is_array($meta) && isset($meta['error']) && is_string($meta['error']) && $meta['error'] !== '') {
            return $meta['error'];
        }
        if (is_array($meta) && isset($meta['last_error']) && is_string($meta['last_error']) && $meta['last_error'] !== '') {
            return $meta['last_error'];
        }

        // Fall back to a generic descriptor so the translator routes us to the
        // 'unknown' bucket with the assistant-investigate action.
        return 'No error message recorded for experiment in status '.$experiment->status->value;
    }

    /** @return list<array<string, mixed>> */
    private function collectEvidence(Experiment $experiment, ?ExperimentStage $stage): array
    {
        $evidence = [];

        if ($stage !== null) {
            $evidence[] = [
                'kind' => 'stage_failure',
                'stage_id' => $stage->id,
                'stage' => $stage->stage instanceof \BackedEnum ? $stage->stage->value : (string) $stage->stage,
                'iteration' => $stage->iteration,
                'retry_count' => $stage->retry_count,
                'recovery_attempts' => $stage->recovery_attempts,
                'duration_ms' => $stage->duration_ms,
                'completed_at' => $stage->completed_at?->toIso8601String(),
            ];
        }

        if ($experiment->agent_id !== null) {
            $cb = CircuitBreakerState::withoutGlobalScopes()
                ->where('agent_id', $experiment->agent_id)
                ->where('team_id', $experiment->team_id)
                ->first();
            if ($cb !== null && $cb->state !== 'closed') {
                $evidence[] = [
                    'kind' => 'circuit_breaker',
                    'agent_id' => $cb->agent_id,
                    'state' => $cb->state,
                    'failure_count' => $cb->failure_count,
                    'opened_at' => $cb->opened_at?->toIso8601String(),
                ];
            }
        }

        $worklog = WorklogEntry::withoutGlobalScopes()
            ->where('team_id', $experiment->team_id)
            ->where('workloggable_type', Experiment::class)
            ->where('workloggable_id', $experiment->id)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        foreach ($worklog as $entry) {
            $evidence[] = [
                'kind' => 'worklog_entry',
                'type' => $entry->type,
                'content' => mb_substr((string) $entry->content, 0, 500),
                'created_at' => $entry->created_at?->toIso8601String(),
            ];
        }

        return $evidence;
    }

    /**
     * @param  list<array<string, mixed>>  $evidence
     * @param  list<array<string, mixed>>  $actions
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function shape(
        string $experimentId,
        string $rootCause,
        string $summary,
        array $evidence,
        array $actions,
        float $confidence,
        array $extra = [],
    ): array {
        return array_merge([
            'experiment_id' => $experimentId,
            'root_cause' => $rootCause,
            'summary' => $summary,
            'evidence' => $evidence,
            'recommended_actions' => $actions,
            'confidence' => round($confidence, 2),
        ], $extra);
    }
}
