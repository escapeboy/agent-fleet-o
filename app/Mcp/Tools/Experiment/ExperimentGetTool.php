<?php

namespace App\Mcp\Tools\Experiment;

use App\Domain\Experiment\Models\Experiment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class ExperimentGetTool extends Tool
{
    protected string $name = 'experiment_get';

    protected string $description = 'Get detailed information about a specific experiment including stages, thesis, budget, and iteration info.';

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

        $experiment = Experiment::with('stages')->find($validated['experiment_id']);

        if (! $experiment) {
            return Response::error('Experiment not found.');
        }

        return Response::text(json_encode([
            'id' => $experiment->id,
            'title' => $experiment->title,
            'thesis' => $experiment->thesis,
            'status' => $experiment->status->value,
            'track' => $experiment->track->value,
            'budget_spent_credits' => $experiment->budget_spent_credits,
            'budget_cap_credits' => $experiment->budget_cap_credits,
            'max_iterations' => $experiment->max_iterations,
            'current_iteration' => $experiment->current_iteration,
            'stages' => $experiment->stages->map(fn ($s) => [
                'type' => $s->stage->value,
                'status' => $s->status->value,
                'output_preview' => $s->output_snapshot
                    ? mb_substr(is_array($s->output_snapshot) ? json_encode($s->output_snapshot) : (string) $s->output_snapshot, 0, 200)
                    : null,
            ])->toArray(),
            'created_at' => $experiment->created_at?->toIso8601String(),
        ]));
    }
}
