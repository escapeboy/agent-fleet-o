<?php

namespace App\Mcp\Tools\Experiment;

use App\Domain\Experiment\Models\UncertaintySignal;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class UncertaintyResolveTool extends Tool
{
    protected string $name = 'resolve_uncertainty';

    protected string $description = 'Resolve a pending uncertainty signal, marking it as resolved with an optional note.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'signal_id' => $schema->string()
                ->description('The UUID of the uncertainty signal to resolve')
                ->required(),
            'resolution_note' => $schema->string()
                ->description('Optional note explaining how the uncertainty was resolved'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'signal_id' => 'required|string',
            'resolution_note' => 'nullable|string',
        ]);

        $teamId = app()->bound('mcp.team_id') ? app('mcp.team_id') : auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }

        $signal = UncertaintySignal::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($validated['signal_id']);

        if (! $signal) {
            return Response::error('Uncertainty signal not found.');
        }

        if ($signal->status !== 'pending') {
            return Response::error("Cannot resolve signal with status '{$signal->status}'.");
        }

        $signal->update([
            'status' => 'resolved',
            'resolved_at' => now(),
            'resolved_by' => auth()->id(),
            'resolution_note' => $validated['resolution_note'] ?? null,
        ]);

        return Response::text(json_encode([
            'success' => true,
            'signal_id' => $signal->id,
            'status' => 'resolved',
        ]));
    }
}
