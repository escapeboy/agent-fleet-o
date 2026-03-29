<?php

namespace App\Mcp\Tools\Experiment;

use App\Domain\Experiment\Models\Experiment;
use App\Infrastructure\AI\Services\ContextHealthService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class ExperimentContextHealthTool extends Tool
{
    protected string $name = 'experiment_context_health';

    protected string $description = 'Check the LLM context health of an experiment. Returns how much of the model\'s context window has been consumed across all pipeline stages (total input tokens vs context window size). Levels: healthy (< 80%), warning (80–89%), critical (>= 90%). At the critical level a handoff artifact is automatically saved.';

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
        $experiment = Experiment::withoutGlobalScopes()
            ->findOrFail($request->get('experiment_id'));

        $health = app(ContextHealthService::class)->getExperimentContextHealth($experiment);

        return Response::text(json_encode([
            'experiment_id' => $health->experimentId,
            'level' => $health->level(),
            'context_used_pct' => $health->contextUsedPercent(),
            'total_input_tokens' => $health->totalInputTokens,
            'context_window_tokens' => $health->contextWindowTokens,
            'is_approaching_limit' => $health->isApproachingLimit,
            'is_critical' => $health->isCritical,
            'primary_model' => $health->primaryModel,
            'recommendation' => match ($health->level()) {
                'critical' => 'Context is at or above 90%. A handoff artifact has been saved. Consider pausing and resuming with a fresh context.',
                'warning' => 'Context is approaching the limit (80–89%). Monitor closely; reduce prompt sizes if possible.',
                default => 'Context usage is within normal bounds.',
            },
        ]));
    }
}
