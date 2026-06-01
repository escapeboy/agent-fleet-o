<?php

namespace App\Mcp\Tools\Metrics;

use App\Domain\Metrics\Actions\TagOutcomeValueAction;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class MetricTagValueTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'metrics_tag_value';

    protected string $description = 'Tag an experiment with realised business value (an outcome receipt) — a closed deal, booked meeting, or resolved ticket. Records value (USD) plus an optional outcome, feeding the ROCS / ROI report. Use this after an agent action delivers measurable value that no payment webhook would capture.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'experiment_id' => $schema->string()->description('The experiment that produced the value.'),
            'value_usd' => $schema->number()->description('Realised value in USD (>= 0).'),
            'outcome' => $schema->string()->nullable()->description('One of: success, partial, failure.'),
            'note' => $schema->string()->nullable()->description('Short free-text note about the outcome.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? null;

        if ($teamId === null) {
            return $this->permissionDeniedError('Authentication required.');
        }

        $experimentId = $request->get('experiment_id');
        $valueUsd = $request->get('value_usd');

        if (! is_string($experimentId) || $experimentId === '') {
            return $this->invalidArgumentError('"experiment_id" is required.');
        }

        if (! is_numeric($valueUsd) || (float) $valueUsd < 0) {
            return $this->invalidArgumentError('"value_usd" must be a number >= 0.');
        }

        $metric = app(TagOutcomeValueAction::class)->execute(
            experimentId: $experimentId,
            valueUsd: (float) $valueUsd,
            teamId: $teamId,
            outcome: $request->get('outcome'),
            note: $request->get('note'),
            source: 'agent',
        );

        if ($metric === null) {
            return $this->notFoundError('experiment', $experimentId);
        }

        return Response::text(json_encode([
            'metric_id' => $metric->id,
            'experiment_id' => $metric->experiment_id,
            'value_usd' => round($metric->value / 100, 2),
            'type' => $metric->type,
        ]));
    }
}
