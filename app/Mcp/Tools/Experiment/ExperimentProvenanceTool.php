<?php

namespace App\Mcp\Tools\Experiment;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Workflow\Services\ArtifactProvenanceFormatter;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class ExperimentProvenanceTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'experiment_provenance';

    protected string $description = 'Render a markdown provenance block for an experiment — the triggering signal, the agents that ran, their outputs, cost, and duration. Intended to be embedded in PR bodies, audit exports, or Slack notifications.';

    public function __construct(
        private readonly ArtifactProvenanceFormatter $formatter,
    ) {}

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
        $validated = $request->validate(['experiment_id' => 'required|string']);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;

        $experiment = Experiment::query()
            ->withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('id', $validated['experiment_id'])
            ->first();

        if (! $experiment) {
            return $this->notFoundError('experiment');
        }

        $markdown = $this->formatter->forExperiment($experiment);

        return Response::text(json_encode([
            'experiment_id' => $experiment->id,
            'markdown' => $markdown,
        ]));
    }
}
