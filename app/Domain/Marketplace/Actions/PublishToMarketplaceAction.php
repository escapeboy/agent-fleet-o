<?php

namespace App\Domain\Marketplace\Actions;

use App\Domain\Agent\Models\Agent;
use App\Domain\Email\Models\EmailTemplate;
use App\Domain\Email\Models\EmailTheme;
use App\Domain\Marketplace\Enums\ListingVisibility;
use App\Domain\Marketplace\Enums\MarketplaceStatus;
use App\Domain\Marketplace\Models\MarketplaceListing;
use App\Domain\Skill\Models\Skill;
use App\Domain\Workflow\Models\Workflow;
use Illuminate\Support\Str;

class PublishToMarketplaceAction
{
    /**
     * Publish a skill, agent, workflow, email theme, or email template to the marketplace.
     */
    public function execute(
        Skill|Agent|Workflow|EmailTheme|EmailTemplate $item,
        string $teamId,
        string $userId,
        string $name,
        string $description,
        ?string $readme = null,
        ?string $category = null,
        array $tags = [],
        ListingVisibility $visibility = ListingVisibility::Public,
    ): MarketplaceListing {
        $type = match (true) {
            $item instanceof Skill => 'skill',
            $item instanceof Agent => 'agent',
            $item instanceof Workflow => 'workflow',
            $item instanceof EmailTheme => 'email_theme',
            $item instanceof EmailTemplate => 'email_template',
        };

        $version = match (true) {
            $item instanceof Skill => $item->current_version ?? '1.0.0',
            $item instanceof Workflow => 'v'.$item->version,
            default => '1.0.0',
        };

        $configSnapshot = match (true) {
            $item instanceof Skill => [
                'type' => $item->type->value,
                'input_schema' => $item->input_schema,
                'output_schema' => $item->output_schema,
                'configuration' => $item->configuration,
                'system_prompt' => $item->system_prompt,
                'risk_level' => $item->risk_level->value,
            ],
            $item instanceof Agent => [
                'role' => $item->role,
                'goal' => $item->goal,
                'provider' => $item->provider,
                'model' => $item->model,
                'capabilities' => $item->capabilities,
                'constraints' => $item->constraints,
            ],
            $item instanceof Workflow => $this->snapshotWorkflow($item),
            $item instanceof EmailTheme => [
                'logo_url' => $item->logo_url,
                'logo_width' => $item->logo_width,
                'background_color' => $item->background_color,
                'canvas_color' => $item->canvas_color,
                'primary_color' => $item->primary_color,
                'text_color' => $item->text_color,
                'heading_color' => $item->heading_color,
                'muted_color' => $item->muted_color,
                'divider_color' => $item->divider_color,
                'font_name' => $item->font_name,
                'font_url' => $item->font_url,
                'font_family' => $item->font_family,
                'heading_font_size' => $item->heading_font_size,
                'body_font_size' => $item->body_font_size,
                'line_height' => $item->line_height,
                'email_width' => $item->email_width,
                'content_padding' => $item->content_padding,
                'company_name' => $item->company_name,
                'company_address' => $item->company_address,
                'footer_text' => $item->footer_text,
            ],
            $item instanceof EmailTemplate => [
                'subject' => $item->subject,
                'preview_text' => $item->preview_text,
                'design_json' => $item->design_json,
                'html_cache' => $item->html_cache,
            ],
        };

        return MarketplaceListing::create([
            'team_id' => $teamId,
            'published_by' => $userId,
            'type' => $type,
            'listable_id' => $item->id,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(6),
            'description' => $description,
            'readme' => $readme,
            'category' => $category,
            'tags' => $tags,
            'status' => MarketplaceStatus::Published,
            'visibility' => $visibility,
            'version' => $version,
            'configuration_snapshot' => $configSnapshot,
        ]);
    }

    public function snapshotWorkflow(Workflow $workflow): array
    {
        $nodes = $workflow->nodes()->with(['agent:id,name', 'skill:id,name'])->get();
        $edges = $workflow->edges()->get();

        return [
            'description' => $workflow->description,
            'max_loop_iterations' => $workflow->max_loop_iterations,
            'estimated_cost_credits' => $workflow->estimated_cost_credits,
            'settings' => $workflow->settings,
            'nodes' => $nodes->map(fn ($n) => [
                'id' => $n->id,
                'type' => $n->type->value,
                'label' => $n->label,
                'position_x' => $n->position_x,
                'position_y' => $n->position_y,
                'config' => $n->config,
                'order' => $n->order,
                'agent_name' => $n->agent?->name,
                'skill_name' => $n->skill?->name,
            ])->toArray(),
            'edges' => $edges->map(fn ($e) => [
                'id' => $e->id,
                'source_node_id' => $e->source_node_id,
                'target_node_id' => $e->target_node_id,
                'condition' => $e->condition,
                'label' => $e->label,
                'is_default' => $e->is_default,
                'sort_order' => $e->sort_order,
            ])->toArray(),
            'node_count' => $nodes->count(),
            'agent_node_count' => $nodes->where('type', 'agent')->count(),
        ];
    }
}
