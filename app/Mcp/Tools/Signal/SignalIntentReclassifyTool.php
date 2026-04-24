<?php

namespace App\Mcp\Tools\Signal;

use App\Domain\Signal\Actions\ClassifySignalIntentAction;
use App\Domain\Signal\Models\Signal;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

#[AssistantTool('write')]
class SignalIntentReclassifyTool extends Tool
{
    protected string $name = 'signal_intent_reclassify';

    protected string $description = 'Re-run LLM intent classification on a signal (e.g. after correcting a bad label). Signals already get classified automatically on ingest; this tool is for re-grading.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'signal_id' => $schema->string()->description('Signal ID to re-classify')->required(),
        ];
    }

    public function handle(Request $request, ClassifySignalIntentAction $action): Response
    {
        $signalId = (string) $request->get('signal_id', '');
        if ($signalId === '') {
            return Response::error('signal_id is required');
        }

        $signal = Signal::find($signalId);
        if ($signal === null) {
            return Response::error("Signal {$signalId} not found");
        }

        // Drop cached classification so action re-runs.
        $metadata = $signal->metadata ?? [];
        unset($metadata['inferred_intent'], $metadata['inferred_intent_reasoning'], $metadata['inferred_intent_classifier'], $metadata['inferred_intent_at']);
        $signal->metadata = $metadata;
        $signal->save();

        $intent = $action->execute($signal);

        return Response::text(json_encode([
            'signal_id' => $signal->id,
            'intent' => $intent?->value,
            'label' => $intent?->label(),
            'metadata' => $signal->fresh()->metadata,
        ]));
    }
}
