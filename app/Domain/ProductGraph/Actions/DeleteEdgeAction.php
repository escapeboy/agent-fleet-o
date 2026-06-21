<?php

namespace App\Domain\ProductGraph\Actions;

use App\Domain\ProductGraph\Models\ProductEdge;

class DeleteEdgeAction
{
    public function execute(ProductEdge $edge): void
    {
        $edge->delete();
    }
}
