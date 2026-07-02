<?php

namespace App\Mcp\Tools\ErrorMode;

use App\Domain\ErrorMode\Actions\AssignErrorModeLeverAction;
use App\Domain\ErrorMode\Enums\ErrorModeLever;
use App\Domain\ErrorMode\Enums\ErrorModeStatus;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class ErrorModeAssignLeverTool extends Tool
{
    protected string $name = 'error_mode_assign_lever';

    protected string $description = 'Assign a remediation lever (and optionally a status) to an error mode — the triage step that turns the catalog into an engineering plan.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'error_mode_id' => $schema->string()
                ->description('Error mode ID')
                ->required(),
            'lever' => $schema->string()
                ->description('Lever: retrieval, reranker, prompt, tool_description, data_prep, guardrails, model_routing, finetuning, unassigned')
                ->enum(['retrieval', 'reranker', 'prompt', 'tool_description', 'data_prep', 'guardrails', 'model_routing', 'finetuning', 'unassigned'])
                ->required(),
            'status' => $schema->string()
                ->description('Optional status: open, mitigated, closed')
                ->enum(['open', 'mitigated', 'closed']),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null) ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::text(json_encode(['error' => 'No current team.']));
        }

        $status = $request->get('status');

        try {
            $mode = app(AssignErrorModeLeverAction::class)->execute(
                teamId: (string) $teamId,
                errorModeId: (string) $request->get('error_mode_id'),
                lever: ErrorModeLever::from($request->get('lever')),
                status: $status !== null ? ErrorModeStatus::from($status) : null,
            );
        } catch (ModelNotFoundException) {
            return Response::text(json_encode(['error' => 'Error mode not found.']));
        }

        return Response::text(json_encode([
            'id' => $mode->id,
            'lever' => $mode->lever,
            'status' => $mode->status,
        ]));
    }
}
