<?php

namespace App\Domain\Chatbot\Enums;

enum KnowledgeSourceType: string
{
    case Document = 'document';
    case Url = 'url';
    case Sitemap = 'sitemap';
    case GitRepository = 'git_repository';

    public function label(): string
    {
        return match($this) {
            self::Document => 'Document',
            self::Url => 'URL',
            self::Sitemap => 'Sitemap',
            self::GitRepository => 'Git Repository',
        };
    }
}
