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
use App\Domain\Workflow\Enums\WorkflowStatus;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowEdge;
use App\Domain\Workflow\Models\WorkflowNode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InstallFromMarketplaceAction
{
    /**
     * Install a marketplace listing into a team's workspace.
     * Clones the skill/agent/workflow with the team's ownership.
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
            $installedWorkflowId = null;

            if ($listing->type === 'skill') {
                $skill = Skill::create([
                    'team_id' => $teamId,
                    'name' => $listing->name,
                    'slug' => Str::slug($listing->name).'-'.Str::random(4),
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
            } elseif ($listing->type === 'agent') {
                $agent = Agent::create([
                    'team_id' => $teamId,
                    'name' => $listing->name,
                    'slug' => Str::slug($listing->name).'-'.Str::random(4),
                    'role' => $snapshot['role'] ?? null,
                    'goal' => $snapshot['goal'] ?? null,
                    'provider' => $snapshot['provider'] ?? 'anthropic',
                    'model' => $snapshot['model'] ?? 'claude-sonnet-4-5',
                    'status' => 'active',
                    'capabilities' => $snapshot['capabilities'] ?? [],
                    'constraints' => $snapshot['constraints'] ?? [],
                ]);

                $installedAgentId = $agent->id;
            } elseif ($listing->type === 'workflow') {
                $workflow = $this->cloneWorkflowFromSnapshot($listing, $snapshot, $teamId, $userId);
                $installedWorkflowId = $workflow->id;
            }

            $listing->increment('install_count');

            return MarketplaceInstallation::create([
                'listing_id' => $listing->id,
                'team_id' => $teamId,
                'installed_by' => $userId,
                'installed_version' => $listing->version,
                'installed_skill_id' => $installedSkillId,
                'installed_agent_id' => $installedAgentId,
                'installed_workflow_id' => $installedWorkflowId,
            ]);
        });
    }

    private function cloneWorkflowFromSnapshot(
        MarketplaceListing $listing,
        array $snapshot,
        string $teamId,
        string $userId,
    ): Workflow {
        $workflow = Workflow::create([
            'team_id' => $teamId,
            'user_id' => $userId,
            'name' => $listing->name,
            'slug' => Str::slug($listing->name).'-'.Str::random(6),
            'description' => $snapshot['description'] ?? $listing->description,
            'status' => WorkflowStatus::Draft,
            'version' => 1,
            'max_loop_iterations' => $snapshot['max_loop_iterations'] ?? 5,
            'estimated_cost_credits' => $snapshot['estimated_cost_credits'] ?? null,
            'settings' => $snapshot['settings'] ?? [],
        ]);

        $nodeIdMap = [];

        foreach ($snapshot['nodes'] ?? [] as $nodeData) {
            $newNode = WorkflowNode::create([
                'workflow_id' => $workflow->id,
                'agent_id' => null, // agents are team-specific, must be reassigned
                'skill_id' => null,
                'type' => $nodeData['type'],
                'label' => $nodeData['label'],
                'position_x' => $nodeData['position_x'] ?? 0,
                'position_y' => $nodeData['position_y'] ?? 0,
                'config' => $nodeData['config'] ?? [],
                'order' => $nodeData['order'] ?? 0,
            ]);
            $nodeIdMap[$nodeData['id']] = $newNode->id;
        }

        foreach ($snapshot['edges'] ?? [] as $edgeData) {
            $sourceId = $nodeIdMap[$edgeData['source_node_id']] ?? null;
            $targetId = $nodeIdMap[$edgeData['target_node_id']] ?? null;

            if ($sourceId && $targetId) {
                WorkflowEdge::create([
                    'workflow_id' => $workflow->id,
                    'source_node_id' => $sourceId,
                    'target_node_id' => $targetId,
                    'condition' => $edgeData['condition'] ?? null,
                    'label' => $edgeData['label'] ?? null,
                    'is_default' => $edgeData['is_default'] ?? false,
                    'sort_order' => $edgeData['sort_order'] ?? 0,
                ]);
            }
        }

        return $workflow;
    }
}
