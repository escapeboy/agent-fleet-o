<?php

namespace App\Domain\Skill\Enums;

enum SkillStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Disabled = 'disabled';
    case Archived = 'archived';

    public function isUsable(): bool
    {
        return $this === self::Active;
    }
}
