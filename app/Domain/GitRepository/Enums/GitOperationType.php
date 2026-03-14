<?php

namespace App\Domain\GitRepository\Enums;

enum GitOperationType: string
{
    case ReadFile = 'read_file';
    case WriteFile = 'write_file';
    case ListFiles = 'list_files';
    case GetTree = 'get_tree';
    case CreateBranch = 'create_branch';
    case Commit = 'commit';
    case Push = 'push';
    case CreatePr = 'create_pr';
    case ListPrs = 'list_prs';
    case Ping = 'ping';

    public function label(): string
    {
        return match ($this) {
            self::ReadFile => 'Read File',
            self::WriteFile => 'Write File',
            self::ListFiles => 'List Files',
            self::GetTree => 'Get File Tree',
            self::CreateBranch => 'Create Branch',
            self::Commit => 'Commit Changes',
            self::Push => 'Push',
            self::CreatePr => 'Create Pull Request',
            self::ListPrs => 'List Pull Requests',
            self::Ping => 'Ping',
        };
    }
}
