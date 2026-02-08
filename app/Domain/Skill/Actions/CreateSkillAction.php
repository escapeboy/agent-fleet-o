<?php

namespace App\Domain\Skill\Actions;

use App\Domain\Skill\Enums\ExecutionType;
use App\Domain\Skill\Enums\RiskLevel;
use App\Domain\Skill\Enums\SkillStatus;
use App\Domain\Skill\Enums\SkillType;
use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Models\SkillVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateSkillAction
{
    public function execute(
        string $teamId,
        string $name,
        SkillType $type,
        string $description = '',
        ExecutionType $executionType = ExecutionType::Sync,
        RiskLevel $riskLevel = RiskLevel::Low,
        array $inputSchema = [],
        array $outputSchema = [],
        array $configuration = [],
        array $costProfile = [],
        array $safetyFlags = [],
        ?string $systemPrompt = null,
        bool $requiresApproval = false,
        ?string $createdBy = null,
    ): Skill {
        return DB::transaction(function () use (
            $teamId, $name, $type, $description, $executionType,
            $riskLevel, $inputSchema, $outputSchema, $configuration,
            $costProfile, $safetyFlags, $systemPrompt, $requiresApproval,
            $createdBy,
        ) {
            $skill = Skill::withoutGlobalScopes()->create([
                'team_id' => $teamId,
                'name' => $name,
                'slug' => Str::slug($name),
                'description' => $description,
                'type' => $type,
                'execution_type' => $executionType,
                'status' => SkillStatus::Draft,
                'risk_level' => $riskLevel,
                'input_schema' => $inputSchema,
                'output_schema' => $outputSchema,
                'configuration' => $configuration,
                'cost_profile' => $costProfile,
                'safety_flags' => $safetyFlags,
                'system_prompt' => $systemPrompt,
                'requires_approval' => $requiresApproval || $riskLevel->requiresApproval(),
                'current_version' => '1.0.0',
            ]);

            // Create initial version snapshot
            SkillVersion::create([
                'skill_id' => $skill->id,
                'version' => '1.0.0',
                'input_schema' => $inputSchema,
                'output_schema' => $outputSchema,
                'configuration' => $configuration,
                'changelog' => 'Initial version',
                'created_by' => $createdBy,
            ]);

            return $skill;
        });
    }
}
