<?php

namespace App\Domain\ProductGraph\Actions;

use App\Domain\ProductGraph\Models\ProductNode;
use Illuminate\Support\Arr;

class UpdateNodeAction
{
    /**
     * Slug is intentionally left stable on rename — it is only a dedup key.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function execute(ProductNode $node, array $attributes): ProductNode
    {
        $node->fill(Arr::only($attributes, [
            'name',
            'status',
            'description',
            'tags',
            'external_ref',
            'metadata',
        ]));
        $node->save();

        return $node->refresh();
    }
}
