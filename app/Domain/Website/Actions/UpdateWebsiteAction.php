<?php

namespace App\Domain\Website\Actions;

use App\Domain\Website\Models\Website;
use Illuminate\Support\Str;

class UpdateWebsiteAction
{
    public function execute(Website $website, array $data): Website
    {
        if (isset($data['slug']) && $data['slug'] !== $website->slug) {
            $base = Str::slug($data['slug']);
            $slug = $base;
            $i = 2;

            while (Website::where('team_id', $website->team_id)->where('slug', $slug)->where('id', '!=', $website->id)->exists()) {
                $slug = $base.'-'.$i++;
            }

            $data['slug'] = $slug;
        }

        $website->update(array_filter([
            'name' => $data['name'] ?? null,
            'slug' => $data['slug'] ?? null,
            'status' => $data['status'] ?? null,
            'settings' => $data['settings'] ?? null,
            'custom_domain' => $data['custom_domain'] ?? null,
        ], fn ($v) => $v !== null));

        return $website->fresh();
    }
}
