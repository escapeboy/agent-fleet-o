<?php

namespace App\Mcp\Tools\Experiment;

use App\Domain\Experiment\Models\UncertaintySignal;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class UncertaintyResolveTool extends Tool
{
    use HasStructuredErrors;

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
            return $this->permissionDeniedError('No current team.');
        }

        $signal = UncertaintySignal::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($validated['signal_id']);

        if (! $signal) {
            return $this->notFoundError('uncertainty signal');
        }

        if ($signal->status !== 'pending') {
            return $this->failedPreconditionError("Cannot resolve signal with status '{$signal->status}'.");
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
