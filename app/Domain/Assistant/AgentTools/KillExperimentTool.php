<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Experiment\Actions\KillExperimentAction;
use App\Domain\Experiment\Models\Experiment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class KillExperimentTool implements Tool
{
    public function name(): string
    {
        return 'kill_experiment';
    }

    public function description(): string
    {
        return 'Kill/terminate an experiment permanently. This is a destructive action.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'experiment_id' => $schema->string()->required()->description('The experiment UUID'),
            'reason' => $schema->string()->description('Reason for killing the experiment'),
        ];
    }

    public function handle(Request $request): string
    {
        $experiment = Experiment::find($request->get('experiment_id'));
        if (! $experiment) {
            return json_encode(['error' => 'Experiment not found']);
        }

        try {
            app(KillExperimentAction::class)->execute($experiment, auth()->id(), $request->get('reason', 'Killed via assistant'));

            return json_encode(['success' => true, 'message' => "Experiment '{$experiment->title}' killed."]);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }
}
