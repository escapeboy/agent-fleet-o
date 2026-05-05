<?php

namespace App\Mcp\Tools\WorldModel;

use App\Domain\WorldModel\Models\TeamWorldModel;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class WorldModelGetTool extends Tool
{
    protected string $name = 'world_model_get';

    protected string $description = 'Get the current team world-model digest — a short briefing of recent signals, experiments, and memories. Injected into every agent system prompt automatically, but also useful to show the user what the AI "knows" about them.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $model = TeamWorldModel::first();

        if ($model === null) {
            return Response::text(json_encode([
                'digest' => null,
                'message' => 'No world-model built yet. Run `world_model_rebuild` to generate one.',
            ]));
        }

        return Response::text(json_encode([
            'id' => $model->id,
            'digest' => $model->digest,
            'provider' => $model->provider,
            'model' => $model->model,
            'stats' => $model->stats,
            'generated_at' => $model->generated_at?->toIso8601String(),
            'is_stale' => $model->isStale(),
        ]));
    }
}
