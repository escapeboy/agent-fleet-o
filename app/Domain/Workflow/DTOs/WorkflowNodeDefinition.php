<?php

namespace App\Domain\Workflow\DTOs;

readonly class WorkflowNodeDefinition
{
    public function __construct(
        /** Unique type slug, e.g. 'send_email', 'score_lead' — must be snake_case */
        public string $type,
        /** Human-readable label shown in the builder UI */
        public string $label,
        /** Heroicons icon name (e.g. 'envelope', 'star') */
        public string $icon = 'puzzle-piece',
        /** Default config values for new nodes of this type */
        public array $defaultConfig = [],
        /** Optional JSON Schema for the node's config panel (for UI form generation) */
        public ?array $configSchema = null,
        /** Category shown in the builder's block panel ('Actions', 'Logic', etc.) */
        public string $category = 'Plugins',
        /** Background color for the node in the visual builder (Tailwind color name) */
        public string $color = 'purple',
    ) {}
}
