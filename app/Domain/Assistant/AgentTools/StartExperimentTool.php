<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class StartExperimentTool implements Tool
{
    public function name(): string
    {
        return 'start_experiment';
    }

    public function description(): string
    {
        return 'Start a draft experiment, kicking off the AI pipeline (scoring -> planning -> building -> executing). The experiment must be in draft status.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'experiment_id' => $schema->string()->required()->description('The experiment UUID'),
        ];
    }

    public function handle(Request $request): string
    {
        $experiment = Experiment::find($request->get('experiment_id'));
        if (! $experiment) {
            return json_encode(['error' => 'Experiment not found']);
        }

        if ($experiment->status !== ExperimentStatus::Draft) {
            return json_encode(['error' => "Cannot start experiment in '{$experiment->status->value}' status. Only draft experiments can be started."]);
        }

        try {
            $result = app(TransitionExperimentAction::class)->execute(
                experiment: $experiment,
                toState: ExperimentStatus::Scoring,
                reason: 'Started via assistant',
                actorId: auth()->id(),
            );

            return json_encode([
                'success' => true,
                'experiment_id' => $result->id,
                'title' => $result->title,
                'status' => $result->status->value,
                'message' => "Experiment '{$result->title}' is now running.",
            ]);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }
}
