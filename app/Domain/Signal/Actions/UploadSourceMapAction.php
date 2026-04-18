<?php

namespace App\Domain\Signal\Actions;

use App\Domain\Signal\Models\SourceMap;

class UploadSourceMapAction
{
    /**
     * Store or replace a source map for a project release.
     *
     * @param  array<string, mixed>  $mapData  Parsed .map file JSON
     */
    public function execute(string $teamId, string $project, string $release, array $mapData): SourceMap
    {
        return SourceMap::updateOrCreate(
            ['team_id' => $teamId, 'project' => $project, 'release' => $release],
            ['map_data' => $mapData],
        );
    }
}
