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

        $fields = ['name', 'slug', 'status', 'settings', 'custom_domain'];
        $update = [];

        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = $data[$field];
            }
        }

        $website->update($update);

        return $website->fresh();
    }
}
