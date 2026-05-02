<?php

namespace App\Domain\Tool\Actions;

use App\Domain\Tool\Models\Toolset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateToolsetAction
{
    public function execute(
        string $teamId,
        string $name,
        string $description,
        array $toolIds,
        array $tags = [],
        bool $isPlatform = false,
        ?string $createdBy = null,
    ): Toolset {
        return DB::transaction(function () use ($teamId, $name, $description, $toolIds, $tags, $isPlatform, $createdBy) {
            $slug = $this->uniqueSlug($teamId, Str::slug($name));

            return Toolset::create([
                'team_id' => $teamId,
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
                'tool_ids' => $toolIds,
                'tags' => $tags,
                'is_platform' => $isPlatform,
                'created_by' => $createdBy,
            ]);
        });
    }

    private function uniqueSlug(string $teamId, string $base): string
    {
        $slug = $base;
        $i = 2;
        while (Toolset::withoutGlobalScopes()->where('team_id', $teamId)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
