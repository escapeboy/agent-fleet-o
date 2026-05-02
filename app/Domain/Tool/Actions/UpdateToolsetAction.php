<?php

namespace App\Domain\Tool\Actions;

use App\Domain\Tool\Models\Toolset;

class UpdateToolsetAction
{
    public function execute(Toolset $toolset, array $data): Toolset
    {
        $toolset->update(array_intersect_key($data, array_flip([
            'name',
            'description',
            'tool_ids',
            'tags',
            'is_platform',
        ])));

        return $toolset->fresh();
    }
}
