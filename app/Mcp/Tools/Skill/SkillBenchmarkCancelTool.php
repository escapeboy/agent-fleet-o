<?php

namespace App\Mcp\Tools\Skill;

use App\Domain\Skill\Actions\CancelSkillBenchmarkAction;
use App\Domain\Skill\Models\SkillBenchmark;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class SkillBenchmarkCancelTool extends Tool
{
    protected string $name = 'skill_benchmark_cancel';

    protected string $description = 'Gracefully cancel a running skill benchmark. The current iteration will finish, then the loop stops.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'benchmark_id' => $schema->string()
                ->description('The benchmark UUID to cancel')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['benchmark_id' => 'required|string']);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }

        $benchmark = SkillBenchmark::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($validated['benchmark_id']);

        if (! $benchmark) {
            return Response::error('Benchmark not found.');
        }

        try {
            $benchmark = app(CancelSkillBenchmarkAction::class)->execute($benchmark);
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage());
        }

        return Response::text(json_encode([
            'benchmark_id' => $benchmark->id,
            'status' => $benchmark->status->value,
            'message' => 'Cancellation requested. The loop will stop after the current iteration.',
        ]));
    }
}
