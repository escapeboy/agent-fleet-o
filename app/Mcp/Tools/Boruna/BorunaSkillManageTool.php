<?php

namespace App\Mcp\Tools\Boruna;

use App\Domain\Skill\Actions\CreateSkillAction;
use App\Domain\Skill\Enums\SkillType;
use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Models\SkillExecution;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool as McpTool;

/**
 * Manage boruna_script skills: create, list, get, and list recent executions.
 */
class BorunaSkillManageTool extends McpTool
{
    protected string $name = 'boruna_skill_manage';

    protected string $description = 'Manage Boruna script skills. Create new boruna_script skills with inline .ax code, list existing Boruna skills, get skill details, or list recent execution history.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description('Action: create | list | get | executions')
                ->enum(['create', 'list', 'get', 'executions'])
                ->required(),
            'name' => $schema->string()
                ->description('(create) Skill name'),
            'description' => $schema->string()
                ->description('(create) Skill description'),
            'script' => $schema->string()
                ->description('(create) The .ax script source code'),
            'policy' => $schema->string()
                ->description('(create) Default capability policy: allow-all or deny-all')
                ->enum(['allow-all', 'deny-all']),
            'boruna_tool_id' => $schema->string()
                ->description('(create) UUID of the Boruna mcp_stdio Tool to use. Auto-detected if omitted.'),
            'skill_id' => $schema->string()
                ->description('(get | executions) UUID of the Boruna skill'),
            'limit' => $schema->integer()
                ->description('(list | executions) Max results (default 20)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'action' => 'required|in:create,list,get,executions',
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'script' => 'nullable|string',
            'policy' => 'nullable|in:allow-all,deny-all',
            'boruna_tool_id' => 'nullable|uuid',
            'skill_id' => 'nullable|uuid',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;

        return match ($validated['action']) {
            'create' => $this->create($validated, $teamId),
            'list' => $this->list($teamId, $validated['limit'] ?? 20),
            'get' => $this->get($validated['skill_id'] ?? null, $teamId),
            'executions' => $this->executions($validated['skill_id'] ?? null, $teamId, $validated['limit'] ?? 20),
        };
    }

    private function create(array $validated, string $teamId): Response
    {
        if (empty($validated['name']) || empty($validated['script'])) {
            return Response::error('name and script are required for create action.');
        }

        $policy = $validated['policy'] ?? 'deny-all';

        try {
            $skill = app(CreateSkillAction::class)->execute(
                teamId: $teamId,
                name: $validated['name'],
                type: SkillType::BorunaScript,
                description: $validated['description'] ?? '',
                systemPrompt: null,
                configuration: array_filter([
                    'script' => $validated['script'],
                    'policy' => $policy,
                    'boruna_tool_id' => $validated['boruna_tool_id'] ?? null,
                ]),
                createdBy: auth()->id(),
            );

            return Response::text(json_encode([
                'success' => true,
                'skill_id' => $skill->id,
                'name' => $skill->name,
                'type' => $skill->type,
                'status' => $skill->status->value,
                'policy' => $policy,
            ]));
        } catch (\Throwable $e) {
            return Response::error("Failed to create Boruna skill: {$e->getMessage()}");
        }
    }

    private function list(string $teamId, int $limit): Response
    {
        $skills = Skill::where('team_id', $teamId)
            ->where('type', SkillType::BorunaScript->value)
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'slug', 'description', 'status', 'configuration', 'execution_count', 'last_executed_at']);

        return Response::text(json_encode([
            'count' => $skills->count(),
            'skills' => $skills->map(function ($s) {
                $cfg = is_array($s->configuration) ? $s->configuration : [];

                return [
                    'id' => $s->id,
                    'name' => $s->name,
                    'slug' => $s->slug,
                    'description' => $s->description,
                    'status' => $s->status,
                    'policy' => $cfg['policy'] ?? 'deny-all',
                    'has_script' => ! empty($cfg['script']),
                    'execution_count' => $s->execution_count ?? 0,
                    'last_executed' => $s->last_executed_at,
                ];
            }),
        ]));
    }

    private function get(?string $skillId, string $teamId): Response
    {
        if (! $skillId) {
            return Response::error('skill_id is required for get action.');
        }

        $skill = Skill::where('id', $skillId)
            ->where('team_id', $teamId)
            ->where('type', SkillType::BorunaScript->value)
            ->first();

        if (! $skill) {
            return Response::error('Boruna skill not found.');
        }

        $cfg = is_array($skill->configuration) ? $skill->configuration : [];

        return Response::text(json_encode([
            'id' => $skill->id,
            'name' => $skill->name,
            'slug' => $skill->slug,
            'description' => $skill->description,
            'status' => $skill->status,
            'policy' => $cfg['policy'] ?? 'deny-all',
            'script' => $cfg['script'] ?? null,
            'boruna_tool_id' => $cfg['boruna_tool_id'] ?? null,
            'execution_count' => $skill->execution_count ?? 0,
            'last_executed' => $skill->last_executed_at,
            'created_at' => $skill->created_at,
        ]));
    }

    private function executions(?string $skillId, string $teamId, int $limit): Response
    {
        $query = SkillExecution::with('skill:id,name')
            ->where('team_id', $teamId)
            ->whereHas('skill', fn ($q) => $q->where('type', SkillType::BorunaScript->value))
            ->orderByDesc('created_at')
            ->limit($limit);

        if ($skillId) {
            $query->where('skill_id', $skillId);
        }

        $executions = $query->get();

        return Response::text(json_encode([
            'count' => $executions->count(),
            'executions' => $executions->map(fn ($e) => [
                'id' => $e->id,
                'skill_id' => $e->skill_id,
                'skill_name' => $e->skill?->name,
                'status' => $e->status,
                'duration_ms' => $e->duration_ms,
                'created_at' => $e->created_at,
            ]),
        ]));
    }
}
