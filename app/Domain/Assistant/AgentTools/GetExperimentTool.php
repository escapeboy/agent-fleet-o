<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Experiment\Models\Experiment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class GetExperimentTool implements Tool
{
    public function name(): string
    {
        return 'get_experiment';
    }

    public function description(): string
    {
        return 'Get detailed information about a specific experiment';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'experiment_id' => $schema->string()->required()->description('The experiment UUID'),
        ];
    }

    public function handle(Request $request): string
    {
        $exp = Experiment::with('stages')->find($request->get('experiment_id'));
        if (! $exp) {
            return json_encode(['error' => 'Experiment not found']);
        }

        return json_encode([
            'id' => $exp->id,
            'title' => $exp->title,
            'thesis' => $exp->thesis,
            'status' => $exp->status->value,
            'track' => $exp->track->value,
            'budget_spent' => $exp->budget_spent_credits,
            'budget_cap' => $exp->budget_cap_credits,
            'max_iterations' => $exp->max_iterations,
            'current_iteration' => $exp->current_iteration,
            'stages' => $exp->stages->map(fn ($s) => [
                'type' => $s->type->value,
                'status' => $s->status->value,
                'output_preview' => mb_substr($s->output ?? '', 0, 200),
            ])->toArray(),
            'created' => $exp->created_at->toIso8601String(),
            'url' => route('experiments.show', $exp),
        ]);
    }
}
