<?php

namespace App\Domain\Website\Actions;

use App\Domain\Shared\Models\Team;
use App\Domain\Website\Enums\WebsiteStatus;
use App\Domain\Website\Models\Website;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CreateWebsiteAction
{
    public function execute(Team $team, array $data, ?User $user = null): Website
    {
        $slug = isset($data['slug']) && $data['slug']
            ? Str::slug($data['slug'])
            : Str::slug($data['name']);

        $slug = $this->uniqueSlug($team->id, $slug);

        $website = Website::create([
            'team_id' => $team->id,
            'user_id' => $user?->id,
            'name' => $data['name'],
            'slug' => $slug,
            'status' => WebsiteStatus::Draft,
        ]);

        Log::info('Website created', ['website_id' => $website->id, 'team_id' => $team->id]);

        return $website;
    }

    private function uniqueSlug(string $teamId, string $base): string
    {
        $slug = $base;
        $i = 2;

        while (Website::where('team_id', $teamId)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
