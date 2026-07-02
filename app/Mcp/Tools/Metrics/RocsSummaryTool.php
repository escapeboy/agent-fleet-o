<?php

namespace App\Mcp\Tools\Metrics;

use App\Domain\Metrics\Services\RocsCalculator;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class RocsSummaryTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'metrics_rocs_summary';

    protected string $description = 'Return on Cognitive Spend: spend vs. delivered value (ROI) per experiment, per agent, and team totals. Joins AI run cost with revenue/business-value metrics. Optionally filter by date range (defaults to last 30 days).';

    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->nullable()->description('ISO date (inclusive) lower bound. Defaults to 30 days ago.'),
            'to' => $schema->string()->nullable()->description('ISO date (inclusive) upper bound. Defaults to now.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null);

        if ($teamId === null) {
            return $this->permissionDeniedError('Authentication required.');
        }

        $from = $request->get('from');
        $to = $request->get('to');

        if ($from !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}/', $from)) {
            return $this->invalidArgumentError('Invalid date format for "from". Use ISO 8601 (YYYY-MM-DD).');
        }

        if ($to !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}/', $to)) {
            return $this->invalidArgumentError('Invalid date format for "to". Use ISO 8601 (YYYY-MM-DD).');
        }

        $since = $from !== null ? Carbon::parse($from) : now()->subDays(30);
        $until = $to !== null ? Carbon::parse($to) : null;

        $report = app(RocsCalculator::class)->forTeam($teamId, $since, $until);

        return Response::text(json_encode($report));
    }
}
