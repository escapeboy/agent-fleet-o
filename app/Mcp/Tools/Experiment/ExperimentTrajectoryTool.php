<?php

namespace App\Mcp\Tools\Experiment;

use App\Domain\Experiment\Actions\ExportTrajectoryAction;
use App\Domain\Experiment\Models\Experiment;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class ExperimentTrajectoryTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'experiment_trajectory_export';

    protected string $description = 'Export an experiment\'s execution trajectory as CSV or JSONL. Returns one row per playbook step with timing, cost, agent, and output data.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'experiment_id' => $schema->string()
                ->description('The experiment UUID')
                ->required(),
            'format' => $schema->string()
                ->description('Export format: csv or jsonl')
                ->enum(['csv', 'jsonl']),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id');
        $experimentId = $request->get('experiment_id');
        $format = $request->get('format') ?? 'csv';

        $experiment = Experiment::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($experimentId);

        if (! $experiment) {
            return $this->notFoundError('experiment');
        }

        $result = (new ExportTrajectoryAction)->execute($experiment, $format);

        return Response::text('# filename: '.$result['filename']."\n".$result['content']);
    }
}
