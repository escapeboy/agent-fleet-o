<?php

namespace App\Mcp\Tools\Signal;

use App\Domain\Signal\Actions\IngestSignalAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use App\Mcp\Attributes\AssistantTool;

#[IsDestructive]
#[AssistantTool('write')]
class SignalIngestTool extends Tool
{
    protected string $name = 'signal_ingest';

    protected string $description = 'Ingest a signal into the platform. Signals are deduplicated by content hash and checked against the blacklist.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'source' => $schema->string()
                ->description('Signal source identifier (e.g. "mcp", "manual", "api")')
                ->required(),
            'payload' => $schema->object()
                ->description('Signal payload data')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'source' => 'required|string|max:255',
            'payload' => 'required',
        ]);

        $payload = $validated['payload'];

        // Handle string payload by wrapping in an array
        if (is_string($payload)) {
            $payload = ['content' => $payload];
        }

        try {
            $teamId = auth()->user()->current_team_id
                ?? (app()->bound('mcp.team_id') ? app('mcp.team_id') : null);

            if (! $teamId) {
                return Response::error('Unauthorized: no active team context.');
            }

            $signal = app(IngestSignalAction::class)->execute(
                sourceType: 'mcp',
                sourceIdentifier: $validated['source'],
                payload: $payload,
                teamId: $teamId,
            );

            if (! $signal) {
                return Response::text(json_encode([
                    'success' => false,
                    'message' => 'Signal was deduplicated or blacklisted.',
                ]));
            }

            return Response::text(json_encode([
                'success' => true,
                'signal_id' => $signal->id,
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
