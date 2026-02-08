<?php

namespace App\Domain\Marketplace\Actions;

use App\Domain\Agent\Models\Agent;
use App\Domain\Marketplace\Models\MarketplaceInstallation;
use App\Domain\Marketplace\Models\MarketplaceListing;
use App\Domain\Skill\Enums\ExecutionType;
use App\Domain\Skill\Enums\RiskLevel;
use App\Domain\Skill\Enums\SkillStatus;
use App\Domain\Skill\Enums\SkillType;
use App\Domain\Skill\Models\Skill;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InstallFromMarketplaceAction
{
    /**
     * Install a marketplace listing into a team's workspace.
     * Clones the skill/agent with the team's ownership.
     */
    public function execute(
        MarketplaceListing $listing,
        string $teamId,
        string $userId,
    ): MarketplaceInstallation {
        return DB::transaction(function () use ($listing, $teamId, $userId) {
            $snapshot = $listing->configuration_snapshot;
            $installedSkillId = null;
            $installedAgentId = null;

            if ($listing->type === 'skill') {
                $skill = Skill::create([
                    'team_id' => $teamId,
                    'name' => $listing->name,
                    'slug' => Str::slug($listing->name) . '-' . Str::random(4),
                    'description' => $listing->description,
                    'type' => SkillType::from($snapshot['type'] ?? 'llm'),
                    'execution_type' => ExecutionType::Sync,
                    'status' => SkillStatus::Active,
                    'risk_level' => RiskLevel::from($snapshot['risk_level'] ?? 'low'),
                    'input_schema' => $snapshot['input_schema'] ?? [],
                    'output_schema' => $snapshot['output_schema'] ?? [],
                    'configuration' => $snapshot['configuration'] ?? [],
                    'system_prompt' => $snapshot['system_prompt'] ?? null,
                    'current_version' => $listing->version,
                ]);

                $installedSkillId = $skill->id;
            } else {
                $agent = Agent::create([
                    'team_id' => $teamId,
                    'name' => $listing->name,
                    'slug' => Str::slug($listing->name) . '-' . Str::random(4),
                    'role' => $snapshot['role'] ?? null,
                    'goal' => $snapshot['goal'] ?? null,
                    'provider' => $snapshot['provider'] ?? 'anthropic',
                    'model' => $snapshot['model'] ?? 'claude-sonnet-4-5',
                    'status' => 'active',
                    'capabilities' => $snapshot['capabilities'] ?? [],
                    'constraints' => $snapshot['constraints'] ?? [],
                ]);

                $installedAgentId = $agent->id;
            }

            $listing->increment('install_count');

            return MarketplaceInstallation::create([
                'listing_id' => $listing->id,
                'team_id' => $teamId,
                'installed_by' => $userId,
                'installed_version' => $listing->version,
                'installed_skill_id' => $installedSkillId,
                'installed_agent_id' => $installedAgentId,
            ]);
        });
    }
}
