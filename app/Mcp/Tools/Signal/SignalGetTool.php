<?php

namespace App\Mcp\Tools\Signal;

use App\Domain\Signal\Models\Signal;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class SignalGetTool extends Tool
{
    protected string $name = 'signal_get';

    protected string $description = 'Get full details of a specific signal including the complete payload and score.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'signal_id' => $schema->string()
                ->description('The signal UUID')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['signal_id' => 'required|string']);

        $signal = Signal::find($validated['signal_id']);

        if (! $signal) {
            return Response::error('Signal not found.');
        }

        return Response::text(json_encode([
            'id' => $signal->id,
            'source_type' => $signal->source_type,
            'source_identifier' => $signal->source_identifier,
            'payload' => $signal->payload,
            'score' => $signal->score,
            'scored_at' => $signal->scored_at?->toIso8601String(),
            'experiment_id' => $signal->experiment_id,
            'created_at' => $signal->created_at?->toIso8601String(),
        ]));
    }
}
