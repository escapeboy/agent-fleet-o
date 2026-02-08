<?php

namespace App\Domain\Shared\Enums;

enum TeamRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';
    case Viewer = 'viewer';

    public function canManageTeam(): bool
    {
        return $this === self::Owner || $this === self::Admin;
    }

    public function canEdit(): bool
    {
        return $this !== self::Viewer;
    }
}
