<?php

namespace App\Domain\Project\Enums;

enum ProjectStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Paused = 'paused';
    case Completed = 'completed';
    case Archived = 'archived';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Active => 'Active',
            self::Paused => 'Paused',
            self::Completed => 'Completed',
            self::Archived => 'Archived',
            self::Failed => 'Failed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Active => 'green',
            self::Paused => 'yellow',
            self::Completed => 'blue',
            self::Archived => 'gray',
            self::Failed => 'red',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Archived]);
    }

    public function isActive(): bool
    {
        return $this === self::Active;
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, self::allowedTransitions()[$this->value] ?? []);
    }

    public static function allowedTransitions(): array
    {
        return [
            'draft' => [self::Active, self::Archived],
            'active' => [self::Paused, self::Completed, self::Failed, self::Archived],
            'paused' => [self::Active, self::Archived],
            'completed' => [self::Archived],
            'failed' => [self::Active, self::Archived],
            'archived' => [],
        ];
    }
}
