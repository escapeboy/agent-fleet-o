<?php

namespace App\Mcp\Tools\Skill;

use App\Domain\Skill\Enums\SkillType;
use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Models\SkillExecution;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class MultiModelConsensusTool extends Tool
{
    protected string $name = 'multi_model_consensus_manage';

    protected string $description = 'Manage multi-model consensus skills. List available consensus skills, inspect a past execution result, or get configuration schema for the models/judge setup.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description('Action: list | get_execution | get_config_schema')
                ->enum(['list', 'get_execution', 'get_config_schema'])
                ->required(),
            'execution_id' => $schema->string()
                ->description('For get_execution: the SkillExecution UUID.'),
            'skill_id' => $schema->string()
                ->description('For get_config_schema: the consensus skill UUID.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'action' => 'required|in:list,get_execution,get_config_schema',
            'execution_id' => 'nullable|string',
            'skill_id' => 'nullable|string',
        ]);

        return match ($validated['action']) {
            'list' => $this->listConsensusSkills(),
            'get_execution' => $this->getExecution($validated['execution_id'] ?? null),
            'get_config_schema' => $this->getConfigSchema($validated['skill_id'] ?? null),
            default => Response::error('Unknown action.'),
        };
    }

    private function listConsensusSkills(): Response
    {
        $skills = Skill::where('type', SkillType::MultiModelConsensus->value)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'description', 'configuration', 'created_at']);

        return Response::text(json_encode([
            'count' => $skills->count(),
            'skills' => $skills->map(function ($s) {
                $cfg = is_array($s->configuration) ? $s->configuration : [];

                return [
                    'id' => $s->id,
                    'name' => $s->name,
                    'slug' => $s->slug,
                    'description' => $s->description,
                    'models' => $cfg['models'] ?? [],
                    'judge_model' => $cfg['judge_model'] ?? null,
                ];
            }),
        ]));
    }

    private function getExecution(?string $executionId): Response
    {
        if (! $executionId) {
            return Response::error('execution_id is required for get_execution action.');
        }

        $execution = SkillExecution::find($executionId);

        if (! $execution) {
            return Response::error('Execution not found.');
        }

        return Response::text(json_encode([
            'id' => $execution->id,
            'skill_id' => $execution->skill_id,
            'status' => $execution->status,
            'output' => $execution->output,
            'confidence_score' => $execution->confidence_score,
            'consensus_level' => $execution->consensus_level,
            'peer_reviews' => $execution->peer_reviews,
            'evaluation_method' => $execution->evaluation_method,
            'judge_model' => $execution->judge_model,
            'duration_ms' => $execution->duration_ms,
            'cost_credits' => $execution->cost_credits,
            'created_at' => $execution->created_at,
        ]));
    }

    private function getConfigSchema(?string $skillId): Response
    {
        $exampleConfig = [
            'models' => [
                ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-5'],
                ['provider' => 'openai', 'model' => 'gpt-4o'],
                ['provider' => 'google', 'model' => 'gemini-2.5-flash'],
            ],
            'judge_model' => ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-5'],
        ];

        if ($skillId) {
            $skill = Skill::find($skillId);
            if ($skill) {
                $exampleConfig = is_array($skill->configuration) ? $skill->configuration : $exampleConfig;
            }
        }

        return Response::text(json_encode([
            'description' => 'Configuration schema for multi_model_consensus skill type.',
            'configuration' => [
                'models' => [
                    'type' => 'array',
                    'description' => 'List of models to run in Stage 1 (parallel generation). Each entry: {provider, model}.',
                    'example' => $exampleConfig['models'],
                ],
                'judge_model' => [
                    'type' => 'object',
                    'description' => 'Model used in Stage 3 (judge synthesis). Single entry: {provider, model}.',
                    'example' => $exampleConfig['judge_model'],
                ],
            ],
            'supported_providers' => ['anthropic', 'openai', 'google'],
            'consensus_levels' => ['strong', 'moderate', 'weak'],
            'output_fields' => [
                'answer' => 'The synthesized best answer from the judge.',
                'dissenting_view' => 'Summary of notable minority view, if any (nullable).',
            ],
            'execution_metadata' => [
                'confidence_score' => 'Float 0.0–1.0 (1.0 = all models agreed perfectly).',
                'consensus_level' => 'strong | moderate | weak',
                'peer_reviews' => 'Object keyed by response label (A/B/C), each containing the reviewer\'s critique.',
                'judge_model' => 'provider/model string identifying the synthesis judge.',
            ],
        ]));
    }
}
