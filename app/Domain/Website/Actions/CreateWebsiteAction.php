<?php

namespace App\Domain\Website\Actions;

use App\Domain\Website\Models\Website;
use Illuminate\Support\Str;

class CreateWebsiteAction
{
    public function execute(string $teamId, string $name, array $data = []): Website
    {
        $slug = $data['slug'] ?? Str::slug($name).'-'.Str::random(6);

        return Website::create([
            'team_id' => $teamId,
            'name' => $name,
            'slug' => $slug,
            'status' => $data['status'] ?? 'draft',
            'custom_domain' => $data['custom_domain'] ?? null,
            'settings' => $data['settings'] ?? [],
        ]);
    }
}
