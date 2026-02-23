<?php

namespace App\Mcp\Tools\Skill;

use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Skill\Enums\SkillType;
use App\Domain\Skill\Models\Skill;
use App\Domain\Workflow\Models\WorkflowNode;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GuardrailTool extends Tool
{
    protected string $name = 'guardrail_manage';

    protected string $description = 'List guardrail skills, get guardrail results for a playbook step, or configure a guardrail on a workflow node.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description('One of: list | get_result | set_node_guardrail | remove_node_guardrail')
                ->enum(['list', 'get_result', 'set_node_guardrail', 'remove_node_guardrail'])
                ->required(),
            'step_id' => $schema->string()
                ->description('For get_result. The playbook step UUID.'),
            'workflow_node_id' => $schema->string()
                ->description('For set_node_guardrail / remove_node_guardrail. The workflow node UUID.'),
            'guardrail_skill_id' => $schema->string()
                ->description('For set_node_guardrail. The guardrail skill UUID to attach.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'action' => 'required|in:list,get_result,set_node_guardrail,remove_node_guardrail',
            'step_id' => 'nullable|string',
            'workflow_node_id' => 'nullable|string',
            'guardrail_skill_id' => 'nullable|string',
        ]);

        return match ($validated['action']) {
            'list' => $this->listGuardrails(),
            'get_result' => $this->getResult($validated['step_id'] ?? null),
            'set_node_guardrail' => $this->setNodeGuardrail(
                $validated['workflow_node_id'] ?? null,
                $validated['guardrail_skill_id'] ?? null,
            ),
            'remove_node_guardrail' => $this->removeNodeGuardrail($validated['workflow_node_id'] ?? null),
            default => Response::error('Unknown action.'),
        };
    }

    private function listGuardrails(): Response
    {
        $guardrails = Skill::where('type', SkillType::Guardrail->value)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'description', 'created_at']);

        return Response::text(json_encode([
            'count' => $guardrails->count(),
            'guardrails' => $guardrails->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'slug' => $s->slug,
                'description' => $s->description,
            ]),
        ]));
    }

    private function getResult(?string $stepId): Response
    {
        if (! $stepId) {
            return Response::error('step_id is required for get_result action.');
        }

        $step = PlaybookStep::find($stepId);

        if (! $step) {
            return Response::error('Playbook step not found.');
        }

        return Response::text(json_encode([
            'step_id' => $stepId,
            'guardrail_result' => $step->guardrail_result,
        ]));
    }

    private function setNodeGuardrail(?string $nodeId, ?string $guardrailSkillId): Response
    {
        if (! $nodeId || ! $guardrailSkillId) {
            return Response::error('workflow_node_id and guardrail_skill_id are required for set_node_guardrail.');
        }

        $node = WorkflowNode::find($nodeId);
        if (! $node) {
            return Response::error('Workflow node not found.');
        }

        $skill = Skill::find($guardrailSkillId);
        if (! $skill || $skill->type->value !== SkillType::Guardrail->value) {
            return Response::error('Skill not found or is not a guardrail type.');
        }

        $node->update(['guardrail_skill_id' => $guardrailSkillId]);

        return Response::text(json_encode([
            'success' => true,
            'workflow_node_id' => $nodeId,
            'guardrail_skill_id' => $guardrailSkillId,
            'guardrail_skill_name' => $skill->name,
        ]));
    }

    private function removeNodeGuardrail(?string $nodeId): Response
    {
        if (! $nodeId) {
            return Response::error('workflow_node_id is required for remove_node_guardrail.');
        }

        $node = WorkflowNode::find($nodeId);
        if (! $node) {
            return Response::error('Workflow node not found.');
        }

        $node->update(['guardrail_skill_id' => null]);

        return Response::text(json_encode(['success' => true, 'workflow_node_id' => $nodeId]));
    }
}
