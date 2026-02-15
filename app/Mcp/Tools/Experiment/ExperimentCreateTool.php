<?php

namespace App\Mcp\Tools\Experiment;

use App\Domain\Experiment\Actions\CreateExperimentAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ExperimentCreateTool extends Tool
{
    protected string $name = 'experiment_create';

    protected string $description = 'Create a new experiment. Specify title and optionally thesis, track, and budget cap.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()
                ->description('Experiment title')
                ->required(),
            'thesis' => $schema->string()
                ->description('Experiment thesis/hypothesis'),
            'track' => $schema->string()
                ->description('Experiment track: growth, retention, revenue, engagement (default: growth)')
                ->enum(['growth', 'retention', 'revenue', 'engagement'])
                ->default('growth'),
            'budget_cap_credits' => $schema->number()
                ->description('Budget cap in credits (default: 10000)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'thesis' => 'nullable|string',
            'track' => 'nullable|string|in:growth,retention,revenue,engagement',
            'budget_cap_credits' => 'nullable|numeric|min:1',
        ]);

        try {
            $experiment = app(CreateExperimentAction::class)->execute(
                userId: auth()->id(),
                title: $validated['title'],
                thesis: $validated['thesis'] ?? $validated['title'],
                track: $validated['track'] ?? 'growth',
                budgetCapCredits: (int) ($validated['budget_cap_credits'] ?? 10000),
                teamId: auth()->user()->current_team_id,
            );

            return Response::text(json_encode([
                'success' => true,
                'experiment_id' => $experiment->id,
                'title' => $experiment->title,
                'status' => $experiment->status->value,
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
