<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Experiment\Actions\ResumeExperimentAction;
use App\Domain\Experiment\Models\Experiment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class ResumeExperimentTool implements Tool
{
    public function name(): string
    {
        return 'resume_experiment';
    }

    public function description(): string
    {
        return 'Resume a paused experiment';
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

        try {
            app(ResumeExperimentAction::class)->execute($experiment, auth()->id());

            return json_encode(['success' => true, 'message' => "Experiment '{$experiment->title}' resumed."]);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }
}
