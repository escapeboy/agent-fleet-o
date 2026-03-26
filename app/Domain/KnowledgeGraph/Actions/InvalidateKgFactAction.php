<?php

namespace App\Domain\KnowledgeGraph\Actions;

use App\Domain\KnowledgeGraph\Models\KgEdge;

class InvalidateKgFactAction
{
    public function execute(KgEdge $edge): KgEdge
    {
        $edge->update(['invalid_at' => now()]);

        activity()->performedOn($edge)->log('kg_fact.invalidated');

        return $edge->fresh();
    }
}
