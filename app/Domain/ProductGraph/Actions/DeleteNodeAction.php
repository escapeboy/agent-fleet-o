<?php

namespace App\Domain\ProductGraph\Actions;

use App\Domain\ProductGraph\Models\ProductNode;

class DeleteNodeAction
{
    /** Connected edges are removed by the cascading FK. */
    public function execute(ProductNode $node): void
    {
        $node->delete();
    }
}
