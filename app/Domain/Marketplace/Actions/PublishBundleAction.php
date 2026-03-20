<?php

namespace App\Domain\Marketplace\Actions;

use App\Domain\Agent\Models\Agent;
use App\Domain\Marketplace\Enums\ListingVisibility;
use App\Domain\Marketplace\Enums\MarketplaceStatus;
use App\Domain\Marketplace\Models\MarketplaceListing;
use App\Domain\Skill\Models\Skill;
use App\Domain\Workflow\Models\Workflow;
use Illuminate\Support\Str;

class PublishBundleAction
{
    public function __construct(
        private readonly PublishToMarketplaceAction $publisher,
    ) {}

    /**
     * Publish a bundle of skills, agents, and/or workflows as a single marketplace listing.
     *
     * Enhanced with cross-entity refs: workflow nodes are automatically linked to
     * agents/skills in the bundle, and ref_keys enable wiring during install.
     *
     * @param  array<array{type: string, id: string, ref_key?: string}>  $items
     * @param  array<string>  $setupHints  Post-install guidance for the user
     * @param  array<array{type: string, service: string, purpose: string}>  $requiredCredentials
     */
    public function execute(
        string $teamId,
        string $userId,
        string $name,
        string $description,
        array $items,
        ?string $readme = null,
        ?string $category = null,
        array $tags = [],
        ListingVisibility $visibility = ListingVisibility::Public,
        array $setupHints = [],
        array $requiredCredentials = [],
    ): MarketplaceListing {
        $configSnapshot = ['items' => []];
        $entityMap = []; // type:id → ref_key

        foreach ($items as $index => $item) {
            $entity = match ($item['type']) {
                'skill' => Skill::findOrFail($item['id']),
                'agent' => Agent::findOrFail($item['id']),
                'workflow' => Workflow::findOrFail($item['id']),
                default => throw new \InvalidArgumentException("Unsupported bundle item type: {$item['type']}"),
            };

            $refKey = $item['ref_key'] ?? Str::slug($entity->name).'_'.($index + 1);

            $snapshot = match ($item['type']) {
                'skill' => [
                    'type' => $entity->type->value,
                    'input_schema' => $entity->input_schema,
                    'output_schema' => $entity->output_schema,
                    'configuration' => $entity->configuration,
                    'system_prompt' => $entity->system_prompt,
                    'risk_level' => $entity->risk_level->value,
                ],
                'agent' => [
                    'role' => $entity->role,
                    'goal' => $entity->goal,
                    'provider' => $entity->provider,
                    'model' => $entity->model,
                    'capabilities' => $entity->capabilities,
                    'constraints' => $entity->constraints,
                ],
                'workflow' => $this->publisher->snapshotWorkflow($entity),
            };

            $configSnapshot['items'][] = [
                'type' => $item['type'],
                'ref_key' => $refKey,
                'name' => $entity->name,
                'description' => $entity->description ?? '',
                'snapshot' => $snapshot,
            ];

            $entityMap[$item['type'].':'.$entity->id] = $refKey;
        }

        // Auto-detect cross-entity refs from workflow nodes
        $configSnapshot['entity_refs'] = $this->detectEntityRefs($items, $entityMap);

        if ($setupHints !== []) {
            $configSnapshot['setup_hints'] = $setupHints;
        }

        if ($requiredCredentials !== []) {
            $configSnapshot['required_credentials'] = $requiredCredentials;
        }

        return MarketplaceListing::create([
            'team_id' => $teamId,
            'published_by' => $userId,
            'type' => 'bundle',
            'listable_id' => null,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(6),
            'description' => $description,
            'readme' => $readme,
            'category' => $category,
            'tags' => $tags,
            'status' => MarketplaceStatus::Published,
            'visibility' => $visibility,
            'version' => '1.0.0',
            'configuration_snapshot' => $configSnapshot,
        ]);
    }

    /**
     * Detect cross-entity references from workflow nodes and agent-skill attachments.
     *
     * @return array<array{workflow_ref?: string, node_label?: string, agent_ref?: string, skill_ref?: string}>
     */
    private function detectEntityRefs(array $items, array $entityMap): array
    {
        $refs = [];

        // Build lookup maps: entity ID → ref_key
        $agentIdToRef = [];
        $skillIdToRef = [];
        $workflowItems = [];

        foreach ($items as $index => $item) {
            $entity = match ($item['type']) {
                'agent' => Agent::find($item['id']),
                'skill' => Skill::find($item['id']),
                'workflow' => Workflow::find($item['id']),
                default => null,
            };

            if (! $entity) {
                continue;
            }

            $refKey = $entityMap[$item['type'].':'.$entity->id] ?? null;
            if (! $refKey) {
                continue;
            }

            match ($item['type']) {
                'agent' => $agentIdToRef[$entity->id] = $refKey,
                'skill' => $skillIdToRef[$entity->id] = $refKey,
                'workflow' => $workflowItems[] = ['entity' => $entity, 'ref_key' => $refKey],
                default => null,
            };
        }

        // Detect workflow node → agent/skill refs
        foreach ($workflowItems as $wf) {
            $nodes = $wf['entity']->nodes()->get();
            foreach ($nodes as $node) {
                if ($node->agent_id && isset($agentIdToRef[$node->agent_id])) {
                    $refs[] = [
                        'workflow_ref' => $wf['ref_key'],
                        'node_label' => $node->label,
                        'agent_ref' => $agentIdToRef[$node->agent_id],
                    ];
                }
                if ($node->skill_id && isset($skillIdToRef[$node->skill_id])) {
                    $refs[] = [
                        'workflow_ref' => $wf['ref_key'],
                        'node_label' => $node->label,
                        'skill_ref' => $skillIdToRef[$node->skill_id],
                    ];
                }
            }
        }

        // Detect agent → skill attachments (via pivot table)
        foreach ($agentIdToRef as $agentId => $agentRef) {
            $agent = Agent::find($agentId);
            if ($agent && method_exists($agent, 'skills')) {
                foreach ($agent->skills()->pluck('skills.id') as $skillId) {
                    if (isset($skillIdToRef[$skillId])) {
                        $refs[] = [
                            'agent_ref' => $agentRef,
                            'skill_ref' => $skillIdToRef[$skillId],
                        ];
                    }
                }
            }
        }

        return $refs;
    }
}
