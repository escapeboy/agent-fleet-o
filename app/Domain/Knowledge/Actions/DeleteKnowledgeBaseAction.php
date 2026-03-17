<?php

namespace App\Domain\Knowledge\Actions;

use App\Domain\Knowledge\Models\KnowledgeBase;

class DeleteKnowledgeBaseAction
{
    public function execute(KnowledgeBase $knowledgeBase): void
    {
        // Remove all chunks first (CASCADE should handle it, but be explicit)
        $knowledgeBase->chunks()->delete();
        $knowledgeBase->delete();
    }
}
