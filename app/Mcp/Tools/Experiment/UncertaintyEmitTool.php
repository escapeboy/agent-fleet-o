<?php

namespace App\Mcp\Tools\Experiment;

use App\Domain\Experiment\Models\ExperimentStage;
use App\Domain\Experiment\Models\UncertaintySignal;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class UncertaintyEmitTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'emit_uncertainty';

    protected string $description = 'Emit a structured uncertainty signal during agent execution when ambiguity is encountered. Lighter than a full approval request. Auto-escalates after TTL expires.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'signal_text' => $schema->string()
                ->description('Description of the uncertainty or ambiguity encountered')
                ->required(),
            'experiment_stage_id' => $schema->string()
                ->description('Optional UUID of the experiment stage this uncertainty relates to'),
            'context_json' => $schema->string()
                ->description('Optional JSON string with additional context for the uncertainty'),
            'ttl_minutes' => $schema->integer()
                ->description('Minutes before auto-escalation (default: 30)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'signal_text' => 'required|string',
            'experiment_stage_id' => 'nullable|string',
            'context_json' => 'nullable|string',
            'ttl_minutes' => 'nullable|integer|min:1|max:10080',
        ]);

        $teamId = app()->bound('mcp.team_id') ? app('mcp.team_id') : auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $context = null;
        if (! empty($validated['context_json'])) {
            $context = json_decode($validated['context_json'], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->invalidArgumentError('Invalid JSON in context_json: '.json_last_error_msg());
            }
        }

        // If a stage ID is provided, verify it belongs to this team
        $stageId = $validated['experiment_stage_id'] ?? null;
        if ($stageId !== null) {
            $stageExists = ExperimentStage::withoutGlobalScopes()
                ->where('id', $stageId)
                ->where('team_id', $teamId)
                ->exists();

            if (! $stageExists) {
                return $this->notFoundError('experiment stage');
            }
        }

        $signal = UncertaintySignal::create([
            'team_id' => $teamId,
            'experiment_stage_id' => $stageId,
            'signal_text' => $validated['signal_text'],
            'context' => $context,
            'status' => 'pending',
            'ttl_minutes' => $validated['ttl_minutes'] ?? 30,
        ]);

        return Response::text(json_encode([
            'success' => true,
            'signal_id' => $signal->id,
            'message' => 'Uncertainty signal emitted. Will auto-escalate after '.$signal->ttl_minutes.' minutes if not resolved.',
        ]));
    }
}
