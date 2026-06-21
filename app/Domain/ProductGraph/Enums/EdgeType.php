<?php

namespace App\Domain\ProductGraph\Enums;

/**
 * Typed structural relationships between product nodes. The {@see impactDirection()}
 * of each type drives blast-radius analysis: which way a change propagates.
 */
enum EdgeType: string
{
    case DependsOn = 'depends_on';
    case PartOf = 'part_of';
    case Uses = 'uses';
    case IntegratesWith = 'integrates_with';
    case Serves = 'serves';
    case Triggers = 'triggers';

    public function label(): string
    {
        return match ($this) {
            self::DependsOn => 'depends on',
            self::PartOf => 'part of',
            self::Uses => 'uses',
            self::IntegratesWith => 'integrates with',
            self::Serves => 'serves',
            self::Triggers => 'triggers',
        };
    }

    /**
     * Which neighbours are affected when a node changes, relative to this edge.
     *
     * - `incoming`: A {depends_on|uses|part_of} B → a change to B (target) affects A (source).
     *   Impact traversal from a node follows edges where it is the TARGET.
     * - `outgoing`: A {serves|triggers} B → a change to A (source) affects B (target).
     *   Impact traversal from a node follows edges where it is the SOURCE.
     * - `both`: integrates_with is bidirectional.
     */
    public function impactDirection(): string
    {
        return match ($this) {
            self::DependsOn, self::Uses, self::PartOf => 'incoming',
            self::Serves, self::Triggers => 'outgoing',
            self::IntegratesWith => 'both',
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
