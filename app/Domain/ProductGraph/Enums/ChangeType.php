<?php

namespace App\Domain\ProductGraph\Enums;

enum ChangeType: string
{
    case CreateNode = 'create_node';
    case UpdateNode = 'update_node';
    case DeleteNode = 'delete_node';
    case CreateEdge = 'create_edge';
    case DeleteEdge = 'delete_edge';

    public function label(): string
    {
        return match ($this) {
            self::CreateNode => 'Create node',
            self::UpdateNode => 'Update node',
            self::DeleteNode => 'Delete node',
            self::CreateEdge => 'Create edge',
            self::DeleteEdge => 'Delete edge',
        };
    }

    public function isEdgeChange(): bool
    {
        return $this === self::CreateEdge || $this === self::DeleteEdge;
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
