<?php

namespace App\Domain\ProductGraph\Enums;

/**
 * Typed nodes in the product graph — the "what we're building" vocabulary.
 * Deliberately product-layer (above code, below wiki), not implementation detail.
 */
enum NodeType: string
{
    case Product = 'product';
    case Feature = 'feature';
    case SubFeature = 'sub_feature';
    case SharedComponent = 'shared_component';
    case AgentSkill = 'agent_skill';
    case Standard = 'standard';
    case Persona = 'persona';
    case TechComponent = 'tech_component';
    case Release = 'release';

    public function label(): string
    {
        return match ($this) {
            self::Product => 'Product',
            self::Feature => 'Feature',
            self::SubFeature => 'Sub-feature',
            self::SharedComponent => 'Shared Component',
            self::AgentSkill => 'Agent Skill',
            self::Standard => 'Standard',
            self::Persona => 'Persona',
            self::TechComponent => 'Tech Component',
            self::Release => 'Release',
        };
    }

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function validationRule(): string
    {
        return 'in:'.implode(',', self::values());
    }
}
