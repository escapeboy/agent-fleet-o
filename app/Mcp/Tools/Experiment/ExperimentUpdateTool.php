<?php

namespace App\Mcp\Tools\Experiment;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class ExperimentUpdateTool extends Tool
{
    protected string $name = 'experiment_update';

    protected string $description = 'Update experiment properties (title, thesis, budget cap). Only allowed for experiments in editable states (Draft or Planning).';

    public function schema(JsonSchema $schema): array
    {
        return [
            'experiment_id' => $schema->string()->description('The experiment ID.')->required(),
            'title' => $schema->string()->description('New title for the experiment.'),
            'thesis' => $schema->string()->description('New thesis for the experiment.'),
            'budget_cap_credits' => $schema->integer()->description('New budget cap in credits.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }

        $experiment = Experiment::withoutGlobalScopes()->where('team_id', $teamId)->find($request->get('experiment_id'));
        if (! $experiment) {
            return Response::error('Experiment not found.');
        }

        $editableStates = [ExperimentStatus::Draft, ExperimentStatus::Planning];
        if (! in_array($experiment->status, $editableStates)) {
            return Response::error('Experiment is not in an editable state. Current status: '.$experiment->status->value);
        }

        $updates = [];
        if ($request->get('title') !== null) {
            $updates['title'] = $request->get('title');
        }
        if ($request->get('thesis') !== null) {
            $updates['thesis'] = $request->get('thesis');
        }
        if ($request->get('budget_cap_credits') !== null) {
            $updates['budget_cap_credits'] = (int) $request->get('budget_cap_credits');
        }

        if (! empty($updates)) {
            $experiment->update($updates);
        }

        return Response::text(json_encode([
            'success' => true,
            'id' => $experiment->id,
            'title' => $experiment->title,
            'status' => $experiment->status->value,
            'budget_cap_credits' => $experiment->budget_cap_credits,
        ]));
    }
}
