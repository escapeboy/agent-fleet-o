<?php

namespace App\Mcp\Tools\Experiment;

use App\Domain\Experiment\Actions\KillExperimentAction;
use App\Domain\Experiment\Models\Experiment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class ExperimentKillTool extends Tool
{
    protected string $name = 'experiment_kill';

    protected string $description = 'Kill/terminate an experiment permanently. This action cannot be undone.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'experiment_id' => $schema->string()
                ->description('The experiment UUID')
                ->required(),
            'reason' => $schema->string()
                ->description('Reason for killing the experiment'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'experiment_id' => 'required|string',
            'reason' => 'nullable|string',
        ]);

        $experiment = Experiment::find($validated['experiment_id']);

        if (! $experiment) {
            return Response::error('Experiment not found.');
        }

        try {
            $result = app(KillExperimentAction::class)->execute(
                $experiment,
                auth()->id(),
                $validated['reason'] ?? 'Killed via MCP',
            );

            return Response::text(json_encode([
                'success' => true,
                'experiment_id' => $result->id,
                'status' => $result->status->value,
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
