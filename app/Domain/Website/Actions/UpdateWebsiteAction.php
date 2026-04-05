<?php

namespace App\Domain\Website\Actions;

use App\Domain\Website\Models\Website;

class UpdateWebsiteAction
{
    public function execute(Website $website, array $data): Website
    {
        $website->update(array_filter([
            'name' => $data['name'] ?? null,
            'slug' => $data['slug'] ?? null,
            'status' => $data['status'] ?? null,
            'custom_domain' => array_key_exists('custom_domain', $data) ? $data['custom_domain'] : null,
            'settings' => $data['settings'] ?? null,
        ], fn ($v) => $v !== null));

        return $website->fresh();
    }
}
