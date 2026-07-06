<?php

namespace App\Mcp\Tools\System;

use App\Domain\Experiment\Models\Experiment;
use App\Infrastructure\AI\LoopDetection\Contracts\TurnHistoryStore;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class LoopDetectionStatusTool extends Tool
{
    protected string $name = 'loop_detection_status';

    protected string $description = 'Report the agent loop-detection configuration (enabled, on-trip action, thresholds) and, for an optional experiment context, how many recent turns are currently buffered in its detection window.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'experiment_id' => $schema->string()
                ->description('Optional experiment UUID to inspect its current loop-detection turn window.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $config = [
            'enabled' => (bool) config('loop_detection.enabled', false),
            'on_trip' => (string) config('loop_detection.on_trip', 'pause'),
            'window' => (int) config('loop_detection.window', 8),
            'similarity' => [
                'threshold' => (float) config('loop_detection.similarity.threshold', 0.9),
                'min_turns' => (int) config('loop_detection.similarity.min_turns', 3),
            ],
            'velocity' => [
                'max_turns' => (int) config('loop_detection.velocity.max_turns', 30),
                'window_seconds' => (int) config('loop_detection.velocity.window_seconds', 60),
            ],
            'stall' => [
                'repeat_threshold' => (int) config('loop_detection.stall.repeat_threshold', 3),
            ],
        ];

        $experimentId = $request->get('experiment_id');
        $bufferedTurns = null;

        // Scope the lookup through TeamScope so a caller can only inspect an
        // experiment in their own team (find() returns null otherwise).
        if (is_string($experimentId) && $experimentId !== '' && Experiment::find($experimentId) !== null) {
            $bufferedTurns = count(app(TurnHistoryStore::class)->recent('exp:'.$experimentId));
        }

        return Response::text((string) json_encode([
            'config' => $config,
            'experiment_id' => $experimentId,
            'buffered_turns' => $bufferedTurns,
        ]));
    }
}
