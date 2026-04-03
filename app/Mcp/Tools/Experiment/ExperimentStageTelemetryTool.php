<?php

namespace App\Mcp\Tools\Experiment;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
use Laravel\Mcp\Http\Request;
use Laravel\Mcp\Http\Response;
use Laravel\Mcp\Schema\JsonSchema;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tool\Attribute\IsReadOnly;

#[IsReadOnly]
class ExperimentStageTelemetryTool extends Tool
{
    protected string $name = 'experiment_stage_telemetry';

    protected string $description = 'Get per-node telemetry for an experiment\'s pipeline stages: token usage, latency, retry rounds, and LLM call counts per stage.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'experiment_id' => $schema->string()
                ->description('The experiment UUID')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'experiment_id' => 'required|string',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }

        $experiment = Experiment::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($validated['experiment_id']);

        if (! $experiment) {
            return Response::error('Experiment not found.');
        }

        $stages = ExperimentStage::withoutGlobalScopes()
            ->where('experiment_id', $experiment->id)
            ->orderBy('iteration')
            ->get(['id', 'stage', 'status', 'iteration', 'duration_ms', 'retry_count', 'telemetry', 'started_at', 'completed_at']);

        $summary = [
            'total_token_input' => 0,
            'total_token_output' => 0,
            'total_llm_calls' => 0,
            'total_latency_ms' => 0,
            'max_retry_round' => 0,
        ];

        $stageData = $stages->map(function (ExperimentStage $stage) use (&$summary) {
            $t = $stage->telemetry ?? [];
            $summary['total_token_input'] += $t['token_input'] ?? 0;
            $summary['total_token_output'] += $t['token_output'] ?? 0;
            $summary['total_llm_calls'] += $t['llm_calls'] ?? 0;
            $summary['total_latency_ms'] += $t['stage_latency_ms'] ?? $stage->duration_ms ?? 0;
            $summary['max_retry_round'] = max($summary['max_retry_round'], $t['retry_round'] ?? 0);

            return [
                'stage' => $stage->stage->value,
                'status' => $stage->status->value,
                'iteration' => $stage->iteration,
                'duration_ms' => $stage->duration_ms,
                'telemetry' => $t,
            ];
        });

        return Response::text(json_encode([
            'experiment_id' => $experiment->id,
            'summary' => $summary,
            'stages' => $stageData,
        ]));
    }
}
