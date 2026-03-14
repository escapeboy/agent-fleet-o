<?php

namespace App\Domain\GitRepository\Enums;

enum GitProvider: string
{
    case GitHub = 'github';
    case GitLab = 'gitlab';
    case Bitbucket = 'bitbucket';
    case Gitea = 'gitea';
    case Generic = 'generic';

    public function label(): string
    {
        return match ($this) {
            self::GitHub => 'GitHub',
            self::GitLab => 'GitLab',
            self::Bitbucket => 'Bitbucket',
            self::Gitea => 'Gitea',
            self::Generic => 'Generic Git',
        };
    }

    public static function detectFromUrl(string $url): self
    {
        if (str_contains($url, 'github.com')) {
            return self::GitHub;
        }

        if (str_contains($url, 'gitlab.com') || str_contains($url, 'gitlab.')) {
            return self::GitLab;
        }

        if (str_contains($url, 'bitbucket.org') || str_contains($url, 'bitbucket.')) {
            return self::Bitbucket;
        }

        return self::Generic;
    }
}
