<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Experiment\Actions\RetryExperimentAction;
use App\Domain\Experiment\Models\Experiment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class RetryExperimentTool implements Tool
{
    public function name(): string
    {
        return 'retry_experiment';
    }

    public function description(): string
    {
        return 'Retry a failed experiment';
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
            app(RetryExperimentAction::class)->execute($experiment, auth()->id());

            return json_encode(['success' => true, 'message' => "Experiment '{$experiment->title}' retrying."]);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }
}
