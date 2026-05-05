<?php

namespace App\Domain\Signal\Enums;

enum CommentAuthorType: string
{
    case Human = 'human';
    case Agent = 'agent';
    case Reporter = 'reporter';
    case Support = 'support';

    public function isWidgetVisible(): bool
    {
        return $this !== self::Human;
    }
}
