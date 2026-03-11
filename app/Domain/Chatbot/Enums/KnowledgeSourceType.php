<?php

namespace App\Domain\Chatbot\Enums;

enum KnowledgeSourceType: string
{
    case Document = 'document';
    case Url = 'url';
    case Sitemap = 'sitemap';
}
