<?php

namespace App\Domain\Tool\Actions;

use App\Domain\Tool\Models\Toolset;
use Illuminate\Support\Facades\DB;

class DeleteToolsetAction
{
    public function execute(Toolset $toolset): void
    {
        DB::transaction(function () use ($toolset) {
            $toolset->agents()->detach();
            $toolset->delete();
        });
    }
}
