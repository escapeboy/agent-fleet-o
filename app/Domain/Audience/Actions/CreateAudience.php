<?php

namespace App\Domain\Audience\Actions;

use App\Domain\Audience\Models\Audience;
use Illuminate\Support\Str;

class CreateAudience
{
    public function execute(
        string $teamId,
        string $name,
        ?string $description = null,
        ?string $topic = null,
    ): Audience {
        return Audience::create([
            'team_id' => $teamId,
            'name' => $name,
            'slug' => $this->uniqueSlug($teamId, $name),
            'description' => $description,
            'topic' => $topic,
        ]);
    }

    /**
     * Generate a team-unique slug, appending -2, -3… on collision.
     */
    private function uniqueSlug(string $teamId, string $name): string
    {
        $base = Str::slug($name) ?: 'audience';
        $slug = $base;
        $suffix = 1;

        while (Audience::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('slug', $slug)
            ->exists()
        ) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }
}
