<?php

namespace App\Mcp\Tools\System;

use App\Infrastructure\AI\Models\LlmRequestLog;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class MetricsModelComparisonTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'system_metrics_model_comparison';

    protected string $description = 'Compare LLM provider/model usage: request counts, cost, latency, token usage. Optionally filter by date range.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->nullable()->description('ISO date (inclusive) lower bound for created_at.'),
            'to' => $schema->string()->nullable()->description('ISO date (inclusive) upper bound for created_at.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? null;

        if ($teamId === null) {
            return $this->permissionDeniedError('Authentication required.');
        }

        $from = $request->input('from');
        $to = $request->input('to');

        if ($from !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}/', $from)) {
            return $this->invalidArgumentError('Invalid date format for "from". Use ISO 8601 (YYYY-MM-DD).');
        }

        if ($to !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}/', $to)) {
            return $this->invalidArgumentError('Invalid date format for "to". Use ISO 8601 (YYYY-MM-DD).');
        }

        $query = LlmRequestLog::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->when($from !== null, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('created_at', '<=', $to));

        $byModel = (clone $query)
            ->selectRaw('provider, model, count(*) as requests, sum(input_tokens) as input_tokens, sum(output_tokens) as output_tokens, sum(cost_credits) as cost_credits, avg(latency_ms) as avg_latency_ms')
            ->whereNotNull('model')
            ->groupBy('provider', 'model')
            ->orderByDesc('requests')
            ->get();

        $totals = (clone $query)
            ->selectRaw('count(*) as total_requests, sum(input_tokens) as total_input_tokens, sum(output_tokens) as total_output_tokens, sum(cost_credits) as total_cost_credits, avg(latency_ms) as avg_latency_ms')
            ->first();

        return Response::text(json_encode([
            'totals' => $totals,
            'by_model' => $byModel,
        ]));
    }
}
