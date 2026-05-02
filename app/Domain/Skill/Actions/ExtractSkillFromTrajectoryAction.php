<?php

namespace App\Domain\Skill\Actions;

use App\Domain\Agent\Models\AgentExecution;
use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Services\NotificationService;
use App\Domain\Skill\Enums\ExecutionType;
use App\Domain\Skill\Enums\RiskLevel;
use App\Domain\Skill\Enums\SkillType;
use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Services\TrajectorySkillExtractor;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;

class ExtractSkillFromTrajectoryAction
{
    public function __construct(
        private readonly AiGatewayInterface $gateway,
        private readonly TrajectorySkillExtractor $extractor,
        private readonly CreateSkillAction $createSkill,
        private readonly NotificationService $notifications,
    ) {}

    public function execute(CrewExecution|AgentExecution $execution): ?Skill
    {
        if ($execution instanceof AgentExecution && $execution->extracted_skill_id) {
            return null;
        }

        $teamId = $execution->team_id;
        if (! $teamId) {
            return null;
        }

        $summary = $this->extractor->buildSummary($execution);

        $prompt = <<<PROMPT
Analyze this execution trajectory and extract a reusable skill definition.

{$summary}

Return JSON with this exact structure:
{
  "name": "Descriptive skill name (max 60 chars)",
  "description": "What this skill does (max 200 chars)",
  "system_prompt": "System prompt for the skill (be specific and detailed)",
  "input_schema": {"type": "object", "properties": {}},
  "output_schema": {"type": "object", "properties": {}},
  "tags": ["tag1", "tag2"]
}

Return ONLY the JSON, no markdown fences.
PROMPT;

        $userId = $execution instanceof CrewExecution
            ? $execution->resolveUserId()
            : Team::ownerIdFor($teamId);

        $response = $this->gateway->complete(new AiRequestDTO(
            provider: 'anthropic',
            model: 'claude-haiku-4-5',
            systemPrompt: 'You are a skill extraction expert. Respond with valid JSON only.',
            userPrompt: $prompt,
            maxTokens: 2048,
            teamId: $teamId,
            userId: $userId,
            purpose: 'trajectory_skill_extraction',
        ));

        $data = json_decode($response->content, true);
        if (! is_array($data) || empty($data['name'])) {
            return null;
        }

        $skill = $this->createSkill->execute(
            teamId: $teamId,
            name: $data['name'],
            type: SkillType::Hybrid,
            description: $data['description'] ?? '',
            executionType: ExecutionType::Sync,
            riskLevel: RiskLevel::Low,
            inputSchema: $data['input_schema'] ?? [],
            outputSchema: $data['output_schema'] ?? [],
            systemPrompt: $data['system_prompt'] ?? null,
        );

        $skill->update(['meta' => [
            'auto_extracted' => true,
            'source_execution_id' => $execution->id,
            'tags' => $data['tags'] ?? [],
        ]]);

        if ($execution instanceof AgentExecution) {
            $execution->update(['extracted_skill_id' => $skill->id]);
        }

        if ($userId) {
            $this->notifications->notify(
                userId: $userId,
                teamId: $teamId,
                type: 'skill_extracted',
                title: 'New skill extracted',
                body: "Skill \"{$skill->name}\" was automatically extracted from a trajectory.",
                actionUrl: '/skills/'.$skill->id,
            );
        }

        return $skill;
    }
}
