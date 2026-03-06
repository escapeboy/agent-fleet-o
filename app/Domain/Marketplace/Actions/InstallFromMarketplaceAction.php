<?php

namespace App\Domain\Marketplace\Actions;

use App\Domain\Agent\Models\Agent;
use App\Domain\Email\Enums\EmailTemplateStatus;
use App\Domain\Email\Enums\EmailTemplateVisibility;
use App\Domain\Email\Enums\EmailThemeStatus;
use App\Domain\Email\Models\EmailTemplate;
use App\Domain\Email\Models\EmailTheme;
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
use Illuminate\Database\Eloquent\Model;
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
            $installedEmailThemeId = null;
            $installedEmailTemplateId = null;

            if ($listing->type === 'bundle') {
                $this->installBundle($listing, is_array($snapshot) ? $snapshot : [], $teamId, $userId);
            } elseif ($listing->type === 'skill') {
                $skill = Skill::create([
                    'team_id' => $teamId,
                    'source_listing_id' => $listing->id,
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
            } elseif ($listing->type === 'email_theme') {
                $theme = EmailTheme::withoutGlobalScopes()->create([
                    'team_id' => $teamId,
                    'name' => $listing->name,
                    'status' => EmailThemeStatus::Draft,
                    'logo_url' => $snapshot['logo_url'] ?? null,
                    'logo_width' => $snapshot['logo_width'] ?? 150,
                    'background_color' => $snapshot['background_color'] ?? '#f4f4f4',
                    'canvas_color' => $snapshot['canvas_color'] ?? '#ffffff',
                    'primary_color' => $snapshot['primary_color'] ?? '#2563eb',
                    'text_color' => $snapshot['text_color'] ?? '#1f2937',
                    'heading_color' => $snapshot['heading_color'] ?? '#111827',
                    'muted_color' => $snapshot['muted_color'] ?? '#6b7280',
                    'divider_color' => $snapshot['divider_color'] ?? '#e5e7eb',
                    'font_name' => $snapshot['font_name'] ?? 'Inter',
                    'font_url' => $snapshot['font_url'] ?? null,
                    'font_family' => $snapshot['font_family'] ?? 'Inter, Arial, sans-serif',
                    'heading_font_size' => $snapshot['heading_font_size'] ?? 24,
                    'body_font_size' => $snapshot['body_font_size'] ?? 16,
                    'line_height' => $snapshot['line_height'] ?? 1.6,
                    'email_width' => $snapshot['email_width'] ?? 600,
                    'content_padding' => $snapshot['content_padding'] ?? 24,
                    'company_name' => $snapshot['company_name'] ?? null,
                    'company_address' => $snapshot['company_address'] ?? null,
                    'footer_text' => $snapshot['footer_text'] ?? null,
                ]);
                $installedEmailThemeId = $theme->id;
            } elseif ($listing->type === 'email_template') {
                $template = EmailTemplate::withoutGlobalScopes()->create([
                    'team_id' => $teamId,
                    'name' => $listing->name,
                    'subject' => $snapshot['subject'] ?? null,
                    'preview_text' => $snapshot['preview_text'] ?? null,
                    'design_json' => $snapshot['design_json'] ?? [],
                    'html_cache' => $snapshot['html_cache'] ?? null,
                    'status' => EmailTemplateStatus::Draft,
                    'visibility' => EmailTemplateVisibility::Private,
                ]);
                $installedEmailTemplateId = $template->id;
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
                'installed_email_theme_id' => $installedEmailThemeId,
                'installed_email_template_id' => $installedEmailTemplateId,
            ]);
        });
    }

    /**
     * Install from a remote marketplace manifest (configuration snapshot).
     * Used by community edition to install from cloud marketplace API.
     */
    public function executeFromManifest(
        string $type,
        array $configuration,
        string $name,
        string $version,
        string $teamId,
        string $userId,
    ): Model {
        return DB::transaction(function () use ($type, $configuration, $name, $version, $teamId, $userId) {
            return match ($type) {
                'skill' => Skill::create([
                    'team_id' => $teamId,
                    'name' => $name,
                    'slug' => Str::slug($name).'-'.Str::random(4),
                    'description' => $configuration['description'] ?? $name,
                    'type' => SkillType::from($configuration['type'] ?? 'llm'),
                    'execution_type' => ExecutionType::Sync,
                    'status' => SkillStatus::Active,
                    'risk_level' => RiskLevel::from($configuration['risk_level'] ?? 'low'),
                    'input_schema' => $configuration['input_schema'] ?? [],
                    'output_schema' => $configuration['output_schema'] ?? [],
                    'configuration' => $configuration['configuration'] ?? [],
                    'system_prompt' => $configuration['system_prompt'] ?? null,
                    'current_version' => $version,
                ]),
                'agent' => Agent::create([
                    'team_id' => $teamId,
                    'name' => $name,
                    'slug' => Str::slug($name).'-'.Str::random(4),
                    'role' => $configuration['role'] ?? null,
                    'goal' => $configuration['goal'] ?? null,
                    'provider' => $configuration['provider'] ?? 'anthropic',
                    'model' => $configuration['model'] ?? 'claude-sonnet-4-5',
                    'status' => 'active',
                    'capabilities' => $configuration['capabilities'] ?? [],
                    'constraints' => $configuration['constraints'] ?? [],
                ]),
                'workflow' => $this->cloneWorkflowFromManifest($configuration, $name, $teamId, $userId),
                'email_theme' => EmailTheme::withoutGlobalScopes()->create(array_merge(
                    ['team_id' => $teamId, 'name' => $name, 'status' => EmailThemeStatus::Draft],
                    array_intersect_key($configuration, array_flip([
                        'logo_url', 'logo_width', 'background_color', 'canvas_color', 'primary_color',
                        'text_color', 'heading_color', 'muted_color', 'divider_color', 'font_name',
                        'font_url', 'font_family', 'heading_font_size', 'body_font_size', 'line_height',
                        'email_width', 'content_padding', 'company_name', 'company_address', 'footer_text',
                    ])),
                )),
                'email_template' => EmailTemplate::withoutGlobalScopes()->create([
                    'team_id' => $teamId,
                    'name' => $name,
                    'subject' => $configuration['subject'] ?? null,
                    'preview_text' => $configuration['preview_text'] ?? null,
                    'design_json' => $configuration['design_json'] ?? [],
                    'html_cache' => $configuration['html_cache'] ?? null,
                    'status' => EmailTemplateStatus::Draft,
                    'visibility' => EmailTemplateVisibility::Private,
                ]),
                default => throw new \InvalidArgumentException("Unsupported marketplace item type: {$type}"),
            };
        });
    }

    private function cloneWorkflowFromManifest(
        array $snapshot,
        string $name,
        string $teamId,
        string $userId,
    ): Workflow {
        $workflow = Workflow::create([
            'team_id' => $teamId,
            'user_id' => $userId,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(6),
            'description' => $snapshot['description'] ?? $name,
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
                'agent_id' => null,
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

    private function installBundle(
        MarketplaceListing $listing,
        array $snapshot,
        string $teamId,
        string $userId,
    ): void {
        foreach ($snapshot['items'] ?? [] as $item) {
            $this->executeFromManifest(
                type: $item['type'],
                configuration: $item['snapshot'],
                name: $item['name'],
                version: $listing->version,
                teamId: $teamId,
                userId: $userId,
            );
        }
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
